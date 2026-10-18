<?php

declare(strict_types=1);

namespace App\Services\Inbox\Concerns;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use App\Services\Social\Meta\GraphError;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

trait MetaGraphRequests
{
    use InboxHttpClient;

    abstract protected function graphException(Response $response): SocialPublishException;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function graphGet(SocialAccount $account, string $url, array $query = []): array
    {
        $parameters = $this->withToken($account, $query);

        return $this->graphResult($this->reach(fn (): Response => $this->socialHttp()->get($url, $parameters)));
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    protected function graphPost(SocialAccount $account, string $url, array $form = []): array
    {
        $parameters = $this->withToken($account, $form);

        return $this->graphResult($this->reach(fn (): Response => $this->socialHttp()->asForm()->post($url, $parameters)));
    }

    /**
     * Subscribes the app to a Page's webhook fields without dropping the
     * fields it already has there: a POST replaces the whole list, and the
     * comment and message setups each add their own.
     *
     * @see https://developers.facebook.com/docs/graph-api/reference/page/subscribed_apps/
     *
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    protected function subscribePageFields(SocialAccount $account, string $graph, string $pageId, array $fields): array
    {
        $appId = (string) config('services.facebook.client_id');
        $current = collect((array) data_get($this->graphGet($account, "{$graph}/{$pageId}/subscribed_apps"), 'data', []))
            ->first(fn (mixed $app): bool => filled($appId) && (string) data_get($app, 'id') === $appId);

        return $this->graphPost($account, "{$graph}/{$pageId}/subscribed_apps", [
            'subscribed_fields' => implode(',', array_values(array_unique([...(array) data_get($current, 'subscribed_fields', []), ...$fields]))),
        ]);
    }

    /**
     * A connection failure message carries the full URL, access token
     * included, so it is replaced before it can reach a log or a stored error.
     *
     * @param  Closure(): Response  $send
     */
    private function reach(Closure $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException) {
            throw new PlatformUnavailableException('Meta Graph API could not be reached');
        }
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function withToken(SocialAccount $account, array $parameters): array
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        return [
            ...array_filter($parameters, fn (mixed $value): bool => filled($value)),
            'access_token' => (string) $account->access_token,
        ];
    }

    /** @return array<string, mixed> */
    private function graphResult(Response $response): array
    {
        if ($response->successful()) {
            return (array) $response->json();
        }

        $code = (int) data_get($response->json(), 'error.code');

        if ($response->status() === SymfonyResponse::HTTP_UNAUTHORIZED || $code === 190) {
            throw new TokenExpiredException((string) data_get($response->json(), 'error.message', 'Meta token rejected'), (string) $code);
        }

        if (GraphError::isTransientFailure($response) || in_array($code, GraphError::RATE_LIMIT_CODES, true)) {
            throw new PlatformUnavailableException('Meta Graph API is unavailable', $response->status());
        }

        throw $this->graphException($response);
    }
}
