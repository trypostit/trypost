<?php

declare(strict_types=1);

namespace App\Services\Http;

use ArrayObject;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\DomCrawler\UriResolver;

/**
 * HTTP fetcher with SSRF protection, timeout, redirect cap and a branded user-agent.
 * All outbound requests to user-supplied URLs must go through here.
 */
final class SafeHttpFetcher
{
    private const string USER_AGENT = 'TryPostBot/1.0 (+https://trypost.it)';

    private const int TIMEOUT_SECONDS = 10;

    private const int MAX_REDIRECTS = 3;

    public function __construct(private readonly HostResolver $resolver = new HostResolver) {}

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $url) === 1) {
            return $url;
        }

        return 'https://'.$url;
    }

    public function get(string $url): Response
    {
        $vetted = new ArrayObject;
        $this->vettedAddresses($url, $vetted);

        $currentUrl = $url;

        // Redirects are followed manually (allow_redirects disabled) so that every
        // hop's Location target is re-validated against the SSRF guard before it is
        // ever requested. A public page could otherwise 302 to an internal host and
        // Guzzle's built-in redirect following would fetch it without re-checking.
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = $this->pinnedHttp($vetted)
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->withOptions(['allow_redirects' => false])
                    ->get($currentUrl);
            } catch (ConnectionException $e) {
                throw new RuntimeException(__('http.errors.unreachable', ['reason' => $e->getMessage()]));
            }

            if (! $response->redirect() || $hop >= self::MAX_REDIRECTS) {
                break;
            }

            $location = $response->header('Location');

            if ($location === '') {
                break;
            }

            $currentUrl = (string) UriResolver::resolve($location, $currentUrl);
            $this->vettedAddresses($currentUrl, $vetted);
        }

        if ($response->redirect()) {
            throw new RuntimeException(__('http.errors.unreachable', ['reason' => 'too many redirects']));
        }

        if ($response->failed()) {
            throw new RuntimeException(__('http.errors.http_status', ['status' => $response->status()]));
        }

        return $response;
    }

    /**
     * Same as get() but never throws — returns null on any failure. Used for logo
     * downloads and other opportunistic fetches where failure is not fatal.
     */
    public function tryGet(string $url): ?Response
    {
        try {
            return $this->get($url);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * Fetch a user-supplied URL without buffering more than the permitted body.
     * Returns null for transport, HTTP, SSRF, redirect, or size failures.
     */
    public function tryGetBody(string $url, int $maxBytes): ?string
    {
        return $this->tryFetch($url, $maxBytes)?->body;
    }

    /**
     * Same as tryGetBody() but also returns the URL the body was served from
     * after following the guarded redirects. The byte cap applies to the
     * decoded body, and an optional deadline bounds the whole transfer,
     * redirects included.
     */
    public function tryFetch(string $url, int $maxBytes, ?CarbonInterface $deadline = null): ?FetchedBody
    {
        return $this->fetchBody($url, $maxBytes, $deadline, truncate: false);
    }

    /**
     * Like tryFetch(), but a body over the cap is cut to its first $maxBytes
     * decoded bytes instead of being refused.
     */
    public function tryFetchPrefix(string $url, int $maxBytes, ?CarbonInterface $deadline = null): ?FetchedBody
    {
        return $this->fetchBody($url, $maxBytes, $deadline, truncate: true);
    }

    private function fetchBody(string $url, int $maxBytes, ?CarbonInterface $deadline, bool $truncate): ?FetchedBody
    {
        if ($maxBytes < 1 || $this->pastDeadline($deadline)) {
            return null;
        }

        $stream = tmpfile();

        if ($stream === false) {
            return null;
        }

        $finalUrl = $url;
        $status = 0;
        $encoding = '';
        $truncated = false;

        try {
            $vetted = new ArrayObject;
            $this->vettedAddresses($url, $vetted);

            try {
                $response = $this->pinnedHttp($vetted)
                    ->withOptions($this->redirectOptions(onRedirect: function (string $target) use (&$finalUrl, $deadline): void {
                        if ($this->pastDeadline($deadline)) {
                            throw new RuntimeException('The fetch exceeded its time budget.');
                        }

                        $finalUrl = $target;
                    }))
                    ->withHeaders(['Accept-Encoding' => 'gzip'])
                    ->connectTimeout(3)
                    ->timeout($this->timeoutWithin($deadline))
                    // Guzzle owns and closes its sink; keep our temporary-file handle independent.
                    ->sink(stream_get_meta_data($stream)['uri'])
                    ->withOptions([
                        'decode_content' => false,
                        'on_headers' => function (ResponseInterface $response) use (&$status, &$encoding): void {
                            $status = $response->getStatusCode();
                            $encoding = strtolower(trim($response->getHeaderLine('Content-Encoding')));
                        },
                        'progress' => function ($total, $downloaded) use ($maxBytes, $deadline, $truncate, &$truncated): void {
                            if ($truncate && $downloaded > $maxBytes) {
                                $truncated = true;
                            }

                            if ((! $truncate && $total > $maxBytes) || $downloaded > $maxBytes) {
                                throw new RuntimeException('Response body exceeded the maximum permitted size.');
                            }

                            if ($this->pastDeadline($deadline)) {
                                throw new RuntimeException('The fetch exceeded its time budget.');
                            }
                        },
                    ])
                    ->get($url);
            } catch (ConnectionException|RuntimeException $exception) {
                if (! $truncated) {
                    throw $exception;
                }
            }

            if (isset($response)) {
                $status = $response->status();
                $encoding = strtolower(trim($response->header('Content-Encoding')));
            }

            if ($status < 200 || $status >= 300) {
                return null;
            }

            rewind($stream);
            $body = $this->decodedBody($stream, $encoding, $maxBytes, $truncate);

            return $body === null ? null : new FetchedBody($body, $finalUrl);
        } catch (ConnectionException|RuntimeException) {
            return null;
        } finally {
            fclose($stream);
        }
    }

    /**
     * @param  resource  $stream
     */
    private function decodedBody($stream, string $encoding, int $maxBytes, bool $truncate): ?string
    {
        if ($encoding === '' || $encoding === 'identity') {
            $body = stream_get_contents($stream, $maxBytes + 1);

            if ($body === false) {
                return null;
            }

            return strlen($body) <= $maxBytes ? $body : ($truncate ? substr($body, 0, $maxBytes) : null);
        }

        if (! in_array($encoding, ['gzip', 'x-gzip', 'deflate'], true)) {
            return null;
        }

        $inflate = inflate_init($encoding === 'deflate' ? ZLIB_ENCODING_DEFLATE : ZLIB_ENCODING_GZIP);

        if ($inflate === false) {
            return null;
        }

        $body = '';

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            $decoded = $chunk === false ? false : @inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);

            if ($decoded === false) {
                return $truncate && $body !== '' ? $body : null;
            }

            $body .= $decoded;

            if (strlen($body) > $maxBytes) {
                return $truncate ? substr($body, 0, $maxBytes) : null;
            }

            if (inflate_get_status($inflate) === ZLIB_STREAM_END) {
                break;
            }
        }

        return $body;
    }

    private function pastDeadline(?CarbonInterface $deadline): bool
    {
        return $deadline !== null && now()->greaterThanOrEqualTo($deadline);
    }

    private function timeoutWithin(?CarbonInterface $deadline): int
    {
        if ($deadline === null) {
            return self::TIMEOUT_SECONDS;
        }

        return max(1, min(self::TIMEOUT_SECONDS, (int) ceil(now()->diffInSeconds($deadline, true))));
    }

    /**
     * Redirect options for requests made through pinnedHttp(): its middleware
     * already guards and pins every hop before it is sent.
     *
     * @param  (Closure(string): void)|null  $onRedirect
     * @return array<string, mixed>
     */
    private function redirectOptions(?Closure $onRedirect = null): array
    {
        return [
            'allow_redirects' => [
                'max' => self::MAX_REDIRECTS,
                'strict' => true,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri) use ($onRedirect): void {
                    if ($onRedirect !== null) {
                        $onRedirect((string) $uri);
                    }
                },
            ],
        ];
    }

    /**
     * A PendingRequest with the SSRF guard applied to $url and redirect handling
     * pre-configured (per-hop re-guard when following, or no redirects). Every
     * request it sends, redirect hops included, connects only to an address
     * the guard vetted. Callers add their own timeout / sink / headers and
     * dispatch to the SAME $url, so a user-supplied URL can never be fetched
     * without the guard and redirect protection. `$onRedirect` runs on every
     * hop after the guard (throw to refuse the hop).
     *
     * @param  (Closure(string): void)|null  $onRedirect
     */
    public function guardedRequest(string $url, bool $followRedirects = true, ?CarbonInterface $deadline = null, ?Closure $onRedirect = null): PendingRequest
    {
        $vetted = new ArrayObject;
        $this->vettedAddresses($url, $vetted);

        $hop = function (string $target) use ($deadline, $onRedirect): void {
            if ($this->pastDeadline($deadline)) {
                throw new RuntimeException('The fetch exceeded its time budget.');
            }

            if ($onRedirect !== null) {
                $onRedirect($target);
            }
        };

        return $this->pinnedHttp($vetted)->withOptions(
            $followRedirects ? $this->redirectOptions($hop) : ['allow_redirects' => false],
        );
    }

    /**
     * Bounds a download by what lands on disk or in memory: the response is
     * requested uncompressed and never decoded, so the wire bytes are the
     * stored bytes and a compressed bomb cannot expand past $maxBytes. The
     * optional deadline bounds the whole transfer, redirects included.
     * `$onExceeded` runs just before an oversized transfer is aborted.
     *
     * @param  (Closure(): void)|null  $onExceeded
     */
    public function limitTransfer(PendingRequest $request, int $maxBytes, ?CarbonInterface $deadline = null, int $timeoutSeconds = self::TIMEOUT_SECONDS, ?Closure $onExceeded = null): PendingRequest
    {
        return $request
            ->withHeaders(['Accept-Encoding' => 'identity'])
            ->connectTimeout(3)
            ->timeout($deadline === null ? $timeoutSeconds : max(1, min($timeoutSeconds, (int) ceil(now()->diffInSeconds($deadline, true)))))
            ->withOptions([
                'decode_content' => false,
                'progress' => function ($total, $downloaded) use ($maxBytes, $deadline, $onExceeded): void {
                    if ($total > $maxBytes || $downloaded > $maxBytes) {
                        if ($onExceeded !== null) {
                            $onExceeded();
                        }

                        throw new RuntimeException('Response body exceeded the maximum permitted size.');
                    }

                    if ($this->pastDeadline($deadline)) {
                        throw new RuntimeException('The fetch exceeded its time budget.');
                    }
                },
            ]);
    }

    /**
     * The first $maxBytes of a response requested with `stream => true`; the
     * rest of the body is never read, so a huge or compressed body costs no
     * more than the prefix.
     */
    public function bodyPrefix(Response $response, int $maxBytes): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';

        while ($maxBytes > strlen($body) && ! $stream->eof()) {
            $chunk = $stream->read($maxBytes - strlen($body));

            if ($chunk === '') {
                break;
            }

            $body .= $chunk;
        }

        $stream->close();

        return $body;
    }

    public function guardAgainstSsrf(string $url): void
    {
        $this->vettedAddresses($url, new ArrayObject);
    }

    /**
     * Validates scheme and host and returns every address the host resolves
     * to (A and AAAA), each of which must be a global unicast address. Empty
     * when the host is an IP literal or private networks are allowed. A host
     * is resolved once per `$vetted` (one fetch, redirects included), so the
     * address that was checked is the address the request is pinned to.
     *
     * @param  ArrayObject<string, list<string>>  $vetted
     * @return list<string>
     */
    private function vettedAddresses(string $url, ArrayObject $vetted): array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) data_get($parts, 'scheme', ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(__('http.errors.invalid_scheme'));
        }

        $host = trim((string) data_get($parts, 'host', ''), '[]');

        if ($host === '') {
            throw new RuntimeException(__('http.errors.missing_host'));
        }

        // Self-hosted operators can opt into fetching their own internal
        // network. Only the private/reserved-IP rejection below is skipped;
        // the scheme and host checks above still always apply.
        if ((bool) config('trypost.security.allow_private_network')) {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $this->assertGlobal($host);

            return [];
        }

        $key = strtolower($host);

        if ($vetted->offsetExists($key)) {
            return $vetted[$key];
        }

        $addresses = $this->resolver->addresses($host);

        if ($addresses === []) {
            throw new RuntimeException(__('http.errors.unresolvable_host', ['host' => $host]));
        }

        foreach ($addresses as $address) {
            $this->assertGlobal($address);
        }

        $vetted[$key] = $addresses;

        return $addresses;
    }

    private function assertGlobal(string $address): void
    {
        $isGlobal = filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE,
        );

        if ($isGlobal === false) {
            throw new RuntimeException(__('http.errors.private_network'));
        }
    }

    /**
     * Http client whose every request, redirect hops included, is guarded and
     * pinned to the addresses the guard vetted, so a second DNS answer
     * (rebinding) is never used.
     *
     * @param  ArrayObject<string, list<string>>  $vetted
     */
    private function pinnedHttp(ArrayObject $vetted): PendingRequest
    {
        return Http::withUserAgent(self::USER_AGENT)->withMiddleware(
            fn (callable $handler): Closure => function (RequestInterface $request, array $options) use ($handler, $vetted) {
                $uri = $request->getUri();
                $addresses = $this->vettedAddresses((string) $uri, $vetted);

                if ($addresses !== []) {
                    $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
                    $pinned = implode(',', array_map(
                        fn (string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
                        $addresses,
                    ));
                    $options['curl'][CURLOPT_RESOLVE] = ["{$uri->getHost()}:{$port}:{$pinned}"];
                }

                return $handler($request, $options);
            },
        );
    }
}
