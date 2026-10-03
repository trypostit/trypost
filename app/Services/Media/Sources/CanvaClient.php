<?php

declare(strict_types=1);

namespace App\Services\Media\Sources;

use App\Dto\CanvaTokens;
use App\Enums\Media\CanvaPreset;
use App\Exceptions\Media\CanvaReconnectRequired;
use App\Models\MediaSourceConnection;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use UnexpectedValueException;

/**
 * Canva Connect API: OAuth with PKCE, designs, PNG exports and the return
 * navigation JWT. Hosts come from config; tokens are never logged.
 */
class CanvaClient
{
    public const array SCOPES = ['design:content:read', 'design:content:write', 'design:meta:read'];

    private const int REFRESH_MARGIN_SECONDS = 60;

    private const int REQUEST_TIMEOUT_SECONDS = 15;

    private const int REFRESH_LOCK_SECONDS = 60;

    private const int REFRESH_WAIT_SECONDS = 30;

    private const int JWKS_TTL_SECONDS = 3600;

    private const int JWKS_REFETCH_COOLDOWN_SECONDS = 60;

    private const string JWKS_CACHE_KEY = 'canva-connect-keys';

    public function authorizeUrl(string $state, string $codeChallenge): string
    {
        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => (string) config('services.canva.client_id'),
            'redirect_uri' => route('app.integrations.canva.callback'),
            'scope' => implode(' ', self::SCOPES),
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => (string) config('trypost.media_sources.canva.code_challenge_method'),
            'state' => $state,
        ], encoding_type: PHP_QUERY_RFC3986);

        return config('trypost.media_sources.canva.authorize_url')."?{$query}";
    }

    public function exchangeCode(string $code, string $verifier): CanvaTokens
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => route('app.integrations.canva.callback'),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Canva code exchange failed ({$response->status()}).");
        }

        return CanvaTokens::fromResponse((array) $response->json());
    }

    /**
     * The connection's access token, refreshed first when it is about to
     * expire. Canva refresh tokens are single-use, so the refresh runs under
     * a lock that outlives the token request and re-reads the row: a
     * concurrent request that already refreshed is reused instead of
     * spending the rotated token twice. A refused token deletes the row only
     * while it still holds the token that was refused.
     *
     * @throws CanvaReconnectRequired
     */
    public function freshAccessToken(MediaSourceConnection $connection): string
    {
        if (! $this->expiresSoon($connection)) {
            return $connection->access_token;
        }

        return Cache::lock("canva-refresh:{$connection->id}", self::REFRESH_LOCK_SECONDS)
            ->block(self::REFRESH_WAIT_SECONDS, function () use ($connection): string {
                $current = MediaSourceConnection::query()->find($connection->id);

                if ($current === null) {
                    throw new CanvaReconnectRequired('The Canva connection is gone.');
                }

                if (! $this->expiresSoon($current)) {
                    $connection->setRawAttributes($current->getAttributes(), true);

                    return $current->access_token;
                }

                $spentRefreshToken = $current->refresh_token;
                $response = $this->tokenRequest([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $spentRefreshToken,
                ]);

                if ($this->isRevoked($response)) {
                    $latest = MediaSourceConnection::query()->find($current->id);

                    if ($latest !== null && $latest->refresh_token !== $spentRefreshToken) {
                        $connection->setRawAttributes($latest->getAttributes(), true);

                        return $latest->access_token;
                    }

                    $latest?->delete();

                    throw new CanvaReconnectRequired('Canva refused the refresh token.');
                }

                if (! $response->successful()) {
                    throw new RuntimeException("Canva token refresh failed ({$response->status()}).");
                }

                $tokens = CanvaTokens::fromResponse((array) $response->json());

                $current->update([
                    'access_token' => $tokens->accessToken,
                    'refresh_token' => $tokens->refreshToken,
                    'expires_at' => now()->addSeconds($tokens->expiresIn),
                ]);
                $connection->setRawAttributes($current->getAttributes(), true);

                return $tokens->accessToken;
            });
    }

    /**
     * The Canva user and team the access token belongs to.
     *
     * @return array{user_id: string, team_id: string}
     */
    public function currentUser(string $accessToken): array
    {
        $response = $this->api($accessToken)->get('/users/me');

        $userId = (string) $response->json('team_user.user_id');
        $teamId = (string) $response->json('team_user.team_id');

        if (! $response->successful() || $userId === '' || $teamId === '') {
            throw new RuntimeException("Canva user lookup failed ({$response->status()}).");
        }

        return ['user_id' => $userId, 'team_id' => $teamId];
    }

    /**
     * @return array{id: string, edit_url: string}
     */
    public function createDesign(string $accessToken, CanvaPreset $preset): array
    {
        $response = $this->api($accessToken)->post('/designs', [
            'type' => 'type_and_asset',
            'design_type' => [
                'type' => 'custom',
                'width' => $preset->width(),
                'height' => $preset->height(),
            ],
        ]);

        $id = (string) $response->json('design.id');
        $editUrl = (string) $response->json('design.urls.edit_url');

        if (! $response->successful() || $id === '' || ! str_starts_with($editUrl, 'https://')) {
            throw new RuntimeException("Canva design creation failed ({$response->status()}).");
        }

        return ['id' => $id, 'edit_url' => $editUrl];
    }

    /**
     * An existing design with a fresh `edit_url`. Canva answers 403 or 404
     * when this token's Canva user cannot open the design.
     *
     * @return array{id: string, edit_url: string}
     */
    public function design(string $accessToken, string $designId): array
    {
        $response = $this->api($accessToken)->get('/designs/'.rawurlencode($designId));

        $id = (string) $response->json('design.id');
        $editUrl = (string) $response->json('design.urls.edit_url');

        if (! $response->successful() || $id === '' || ! str_starts_with($editUrl, 'https://')) {
            throw new RuntimeException("Canva design lookup failed ({$response->status()}).");
        }

        return ['id' => $id, 'edit_url' => $editUrl];
    }

    public function startExport(string $accessToken, string $designId): string
    {
        $response = $this->api($accessToken)->post('/exports', [
            'design_id' => $designId,
            'format' => ['type' => 'png', 'pages' => [1]],
        ]);

        $jobId = (string) $response->json('job.id');

        if (! $response->successful() || $jobId === '') {
            throw new RuntimeException("Canva export failed to start ({$response->status()}).");
        }

        return $jobId;
    }

    /**
     * @return array{status: string, urls: list<string>}
     */
    public function export(string $accessToken, string $jobId): array
    {
        $response = $this->api($accessToken)->get('/exports/'.rawurlencode($jobId));

        if (! $response->successful()) {
            throw new RuntimeException("Canva export lookup failed ({$response->status()}).");
        }

        return [
            'status' => (string) $response->json('job.status'),
            'urls' => array_values(array_filter((array) $response->json('job.urls', []), 'is_string')),
        ];
    }

    /**
     * The claims of a return navigation JWT whose signature matches one of
     * Canva's published keys. Throws when the token is malformed, unsigned
     * by Canva or expired; the caller checks the remaining claims. A key id
     * missing from the cached set refetches the keys once (at most once a
     * minute), so a key rotation does not wait for the cache to expire.
     *
     * @return array<string, mixed>
     */
    public function verifyReturnJwt(string $jwt): array
    {
        try {
            return $this->decodeReturnJwt($jwt);
        } catch (UnexpectedValueException $exception) {
            if (! str_contains($exception->getMessage(), '"kid" invalid')
                || ! Cache::add('canva-connect-keys-refetched', true, self::JWKS_REFETCH_COOLDOWN_SECONDS)) {
                throw $exception;
            }

            Cache::forget(self::JWKS_CACHE_KEY);

            return $this->decodeReturnJwt($jwt);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeReturnJwt(string $jwt): array
    {
        $keys = Cache::remember(self::JWKS_CACHE_KEY, self::JWKS_TTL_SECONDS, function (): array {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->acceptJson()->get($this->apiUrl('/connect/keys'));

            if (! $response->successful() || ! is_array($response->json('keys'))) {
                throw new RuntimeException("Canva keys lookup failed ({$response->status()}).");
            }

            return (array) $response->json();
        });

        $keys['keys'] = array_map(fn (array $key): array => $key + ['alg' => data_get($key, 'kty') === 'OKP' ? 'EdDSA' : 'RS256'], (array) data_get($keys, 'keys', []));

        return (array) json_decode((string) json_encode(JWT::decode($jwt, JWK::parseKeySet($keys))), true);
    }

    private function expiresSoon(MediaSourceConnection $connection): bool
    {
        return $connection->expires_at->lessThanOrEqualTo(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    private function isRevoked(Response $response): bool
    {
        return in_array($response->status(), [HttpResponse::HTTP_BAD_REQUEST, HttpResponse::HTTP_UNAUTHORIZED], true)
            && $response->json('error') === 'invalid_grant';
    }

    /**
     * @param  array<string, string>  $form
     */
    private function tokenRequest(array $form): Response
    {
        return Http::asForm()
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->withBasicAuth((string) config('services.canva.client_id'), (string) config('services.canva.client_secret'))
            ->post($this->apiUrl('/oauth/token'), $form);
    }

    private function api(string $accessToken): PendingRequest
    {
        return Http::baseUrl($this->apiUrl(''))
            ->withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }

    private function apiUrl(string $path): string
    {
        return rtrim((string) config('trypost.media_sources.canva.api'), '/').$path;
    }
}
