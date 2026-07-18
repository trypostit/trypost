<?php

declare(strict_types=1);

namespace App\Services\Brand;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
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
        $this->guardAgainstSsrf($url);

        $currentUrl = $url;

        // Redirects are followed manually (allow_redirects disabled) so that every
        // hop's Location target is re-validated against the SSRF guard before it is
        // ever requested. A public page could otherwise 302 to an internal host and
        // Guzzle's built-in redirect following would fetch it without re-checking.
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            try {
                $response = Http::timeout(self::TIMEOUT_SECONDS)
                    ->withUserAgent(self::USER_AGENT)
                    ->withOptions(['allow_redirects' => false])
                    ->get($currentUrl);
            } catch (ConnectionException $e) {
                throw new RuntimeException(__('workspaces.create.autofill_errors.unreachable', ['reason' => $e->getMessage()]));
            }

            if (! $response->redirect() || $hop >= self::MAX_REDIRECTS) {
                break;
            }

            $location = $response->header('Location');

            if ($location === '') {
                break;
            }

            $currentUrl = (string) UriResolver::resolve($location, $currentUrl);
            $this->guardAgainstSsrf($currentUrl);
        }

        if ($response->redirect()) {
            throw new RuntimeException(__('workspaces.create.autofill_errors.unreachable', ['reason' => 'too many redirects']));
        }

        if ($response->failed()) {
            throw new RuntimeException(__('workspaces.create.autofill_errors.http_status', ['status' => $response->status()]));
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
     * Guzzle allow_redirects options that re-run the SSRF guard on every hop.
     * For callers that follow redirects on user-supplied URLs with methods/bodies
     * that SafeHttpFetcher::get() cannot express.
     *
     * @return array<string, mixed>
     */
    public function redirectGuardOptions(int $max = self::MAX_REDIRECTS): array
    {
        return [
            'allow_redirects' => [
                'max' => $max,
                'strict' => true,
                'protocols' => ['http', 'https'],
                'on_redirect' => function ($request, $response, $uri): void {
                    $this->guardAgainstSsrf((string) $uri);
                },
            ],
        ];
    }

    /**
     * A PendingRequest with the SSRF guard applied to $url and redirect handling
     * pre-configured (per-hop re-guard when following, or no redirects). Callers
     * add their own timeout / sink / headers and dispatch to the SAME $url, so a
     * user-supplied URL can never be fetched without the guard and redirect
     * protection.
     */
    public function guardedRequest(string $url, bool $followRedirects = true): PendingRequest
    {
        $this->guardAgainstSsrf($url);

        return Http::withUserAgent(self::USER_AGENT)->withOptions(
            $followRedirects ? $this->redirectGuardOptions() : ['allow_redirects' => false],
        );
    }

    public function guardAgainstSsrf(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) data_get($parts, 'scheme', ''));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException(__('workspaces.create.autofill_errors.invalid_scheme'));
        }

        $host = (string) data_get($parts, 'host', '');

        if ($host === '') {
            throw new RuntimeException(__('workspaces.create.autofill_errors.missing_host'));
        }

        // Self-hosted operators can opt into fetching their own internal
        // network. Only the private/reserved-IP rejection below is skipped;
        // the scheme and host checks above still always apply.
        if ((bool) config('trypost.security.allow_private_network')) {
            return;
        }

        $ip = gethostbyname($host);

        if ($ip === $host && filter_var($host, FILTER_VALIDATE_IP) === false) {
            throw new RuntimeException(__('workspaces.create.autofill_errors.unresolvable_host', ['host' => $host]));
        }

        $isPublic = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        );

        if ($isPublic === false) {
            throw new RuntimeException(__('workspaces.create.autofill_errors.private_network'));
        }
    }
}
