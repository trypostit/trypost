<?php

declare(strict_types=1);

namespace App\Socialite;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\RequestOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use RuntimeException;
use Throwable;

/**
 * Generic OpenID Connect login, so any spec-compliant identity provider
 * (Authentik, Keycloak, Pocket ID, Zitadel, Entra ID, ...) can sign users in
 * without needing its own driver. Endpoints are read from the provider's
 * discovery document rather than configured one by one.
 *
 * On top of the plain OAuth2 flow Socialite gives us:
 *  - PKCE (S256) on every authorization request,
 *  - a per-request nonce that has to come back inside the ID token,
 *  - the ID token verified against the provider's JWKS, plus issuer, audience
 *    and expiry checks.
 */
class OidcProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopeSeparator = ' ';

    protected $usesPKCE = true;

    /**
     * Where the nonce waits while the user is at the identity provider. It is
     * pulled (and thereby consumed) when the ID token is validated, so a token
     * cannot be replayed against a later login attempt.
     */
    private const NONCE_SESSION_KEY = 'oidc.nonce';

    /**
     * The token endpoint response of the current exchange, kept so the
     * controller can stash the ID token for RP-initiated logout.
     *
     * @var array<string, mixed>
     */
    private array $tokenResponse = [];

    /**
     * Verified claims of the current ID token. Some providers put group
     * membership only in here and not in the userinfo response.
     *
     * @var array<string, mixed>
     */
    private array $idTokenClaims = [];

    /**
     * The provider's discovery document. Cached per issuer because it changes
     * rarely and every login would otherwise pay for the extra round-trip.
     *
     * @return array<string, mixed>
     */
    public function discovery(): array
    {
        $url = $this->discoveryUrl();

        return Cache::remember('oidc.discovery.'.md5($url), now()->addHour(), function () use ($url): array {
            $document = json_decode((string) $this->getHttpClient()->get($url, [
                RequestOptions::HEADERS => ['Accept' => 'application/json'],
            ])->getBody(), true);

            if (! is_array($document) || blank($document['authorization_endpoint'] ?? null) || blank($document['token_endpoint'] ?? null)) {
                throw new RuntimeException("The OIDC discovery document at {$url} is missing required endpoints.");
            }

            return $document;
        });
    }

    /**
     * The provider's logout endpoint, or null when it does not offer one.
     */
    public function endSessionEndpoint(): ?string
    {
        try {
            $endpoint = $this->discovery()['end_session_endpoint'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($endpoint) && filled($endpoint) ? $endpoint : null;
    }

    /**
     * The ID token of the exchange that just happened, for use as
     * `id_token_hint` on logout.
     */
    public function idToken(): ?string
    {
        $idToken = $this->tokenResponse['id_token'] ?? null;

        return is_string($idToken) ? $idToken : null;
    }

    /**
     * @return array<int, string>
     */
    public function getScopes(): array
    {
        $configured = (string) config('services.oidc.scopes', '');

        $scopes = filled($configured)
            ? (array) preg_split('/[\s,]+/', trim($configured), -1, PREG_SPLIT_NO_EMPTY)
            : ['profile', 'email'];

        // `openid` is what makes this an OIDC request at all, so it is never
        // left to configuration.
        return array_values(array_unique(['openid', ...$scopes]));
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->discovery()['authorization_endpoint'], $state);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCodeFields($state = null): array
    {
        $nonce = Str::random(40);

        $this->request->session()->put(self::NONCE_SESSION_KEY, $nonce);

        return array_merge(parent::getCodeFields($state), ['nonce' => $nonce]);
    }

    protected function getTokenUrl(): string
    {
        return $this->discovery()['token_endpoint'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code): array
    {
        $this->tokenResponse = parent::getAccessTokenResponse($code);

        $this->validateIdToken($this->tokenResponse['id_token'] ?? null);

        return $this->tokenResponse;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        $endpoint = $this->discovery()['userinfo_endpoint'] ?? null;

        if (blank($endpoint)) {
            throw new RuntimeException('The identity provider does not expose a userinfo endpoint.');
        }

        $claims = json_decode((string) $this->getHttpClient()->get($endpoint, [
            RequestOptions::HEADERS => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$token,
            ],
        ])->getBody(), true);

        $claims = is_array($claims) ? $claims : [];

        // Claims that only the ID token carries (commonly `groups`) would
        // otherwise be lost, so fill in whatever userinfo did not return.
        return $claims + $this->idTokenClaims;
    }

    /**
     * @param  array<string, mixed>  $user
     */
    protected function mapUserToObject(array $user): User
    {
        $id = data_get($user, 'sub');

        // Without a subject there is nothing stable to tie the account to, so
        // fail instead of creating a user that can never log in again.
        if (blank($id)) {
            throw new RuntimeException('The identity provider did not return a subject claim.');
        }

        return (new User)->setRaw($user)->map([
            'id' => (string) $id,
            'nickname' => data_get($user, 'preferred_username'),
            'name' => data_get($user, 'name') ?: trim((string) data_get($user, 'given_name').' '.(string) data_get($user, 'family_name')) ?: data_get($user, 'preferred_username'),
            'email' => data_get($user, 'email'),
            'avatar' => data_get($user, 'picture'),
        ]);
    }

    private function discoveryUrl(): string
    {
        $configured = (string) config('services.oidc.discovery_url', '');

        if (blank($configured)) {
            throw new RuntimeException('OIDC_DISCOVERY_URL is not configured.');
        }

        // Accept a bare issuer URL as well; the well-known path is always the
        // same and pasting the issuer is the easier thing to get right.
        return Str::contains($configured, '/.well-known/')
            ? $configured
            : rtrim($configured, '/').'/.well-known/openid-configuration';
    }

    /**
     * Verifies the ID token's signature and the claims that bind it to this
     * client and this login attempt.
     */
    private function validateIdToken(mixed $idToken): void
    {
        if (! is_string($idToken) || blank($idToken)) {
            throw new RuntimeException('The identity provider did not return an ID token.');
        }

        $expectedNonce = $this->request->session()->pull(self::NONCE_SESSION_KEY);

        $claims = $this->decodeIdToken($idToken);

        $issuer = $this->discovery()['issuer'] ?? null;

        if (filled($issuer) && ($claims['iss'] ?? null) !== $issuer) {
            throw new RuntimeException('The ID token was issued by a different provider than configured.');
        }

        if (! in_array($this->clientId, (array) ($claims['aud'] ?? []), true)) {
            throw new RuntimeException('The ID token was not issued for this client.');
        }

        if (! is_string($expectedNonce) || ! hash_equals($expectedNonce, (string) ($claims['nonce'] ?? ''))) {
            throw new RuntimeException('The ID token nonce does not match this login attempt.');
        }

        $this->idTokenClaims = $claims;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeIdToken(string $idToken): array
    {
        // A little leeway so a clock a few seconds out of step does not lock
        // people out. Restored afterwards because it is global to the library.
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = 60;

        try {
            try {
                return $this->decodeWith($idToken, $this->signingKeys());
            } catch (Throwable) {
                // A provider that just rotated its signing keys would otherwise
                // lock everyone out until the cache expires, so try once more
                // with freshly fetched keys before giving up.
                return $this->decodeWith($idToken, $this->signingKeys(refresh: true));
            }
        } catch (Throwable $e) {
            throw new RuntimeException('The ID token could not be verified: '.$e->getMessage(), previous: $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /**
     * @param  array<string, Key>  $keys
     * @return array<string, mixed>
     */
    private function decodeWith(string $idToken, array $keys): array
    {
        return (array) json_decode(json_encode(JWT::decode($idToken, $keys)), true);
    }

    /**
     * @return array<string, Key>
     */
    private function signingKeys(bool $refresh = false): array
    {
        $jwksUri = $this->discovery()['jwks_uri'] ?? null;

        if (blank($jwksUri)) {
            throw new RuntimeException('The identity provider does not publish a JWKS, so ID tokens cannot be verified.');
        }

        $cacheKey = 'oidc.jwks.'.md5((string) $jwksUri);

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        // Cache the raw document rather than the parsed key set: parsed keys
        // hold OpenSSL key objects, which no cache store can serialize.
        // Parsing again on every login is cheap by comparison.
        $jwks = Cache::remember($cacheKey, now()->addHour(), function () use ($jwksUri): array {
            $document = json_decode((string) $this->getHttpClient()->get($jwksUri, [
                RequestOptions::HEADERS => ['Accept' => 'application/json'],
            ])->getBody(), true);

            if (! is_array($document) || blank($document['keys'] ?? null)) {
                throw new RuntimeException("The JWKS at {$jwksUri} is empty.");
            }

            return $document;
        });

        return JWK::parseKeySet($jwks);
    }
}
