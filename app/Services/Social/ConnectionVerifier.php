<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\BlueskyPublishException;
use App\Exceptions\Social\DiscordPublishException;
use App\Exceptions\Social\LinkedInPublishException;
use App\Exceptions\Social\MastodonPublishException;
use App\Exceptions\Social\PinterestPublishException;
use App\Exceptions\Social\TelegramPublishException;
use App\Exceptions\Social\TikTokPublishException;
use App\Exceptions\Social\XPublishException;
use App\Exceptions\Social\YouTubePublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Social\Discord\DiscordClient;
use App\Services\Social\Meta\GraphError;
use App\Services\Social\Telegram\TelegramApi;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ConnectionVerifier
{
    /**
     * Read and connect timeouts for a token refresh. Stated explicitly, even
     * though they match the client's defaults, so a change to those cannot
     * silently break the lock invariant below. Generous on purpose: giving up
     * on a request the provider already processed loses a single-use
     * refresh_token for good.
     */
    public const REFRESH_TIMEOUT_SECONDS = 30;

    public const REFRESH_CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Must exceed the slowest refresh the timeouts above allow — Bluesky's two
     * sequential calls — or the lock lapses mid-flight and a second process
     * reuses the same single-use refresh_token. Pinned by a test.
     */
    public const REFRESH_LOCK_SECONDS = 120;

    /**
     * Verify that a social account connection is still valid.
     *
     * @throws TokenExpiredException if the connection is invalid
     * @throws PlatformUnavailableException if the platform's API is down
     */
    public function verify(SocialAccount $account): bool
    {
        // Hard-expired tokens cannot make API calls — refresh is mandatory.
        // For tokens that are still valid OR only "expiring soon", try the
        // verify endpoint FIRST with the current access_token. This avoids
        // rotating refresh_tokens unnecessarily — X and Bluesky single-use
        // theirs, so refreshing during a race disconnects an account whose
        // access_token still works. (LinkedIn returns the same token.)
        if ($account->is_token_expired) {
            return $this->refreshThenVerify($account);
        }

        try {
            return $this->callVerifyEndpoint($account);
        } catch (TokenExpiredException $e) {
            if (! $account->platform->hasTokenRefreshFlow()) {
                // Facebook/InstagramFacebook (Page tokens) and Mastodon
                // tokens don't expire, and Telegram/Discord authenticate
                // with one bot token shared across every connected account
                // of that platform — none of them have anything to refresh,
                // so retrying would just repeat this identical rejection
                // while burning a call against a budget shared app-wide.
                throw $e;
            }

            // Verify returned 401: the access_token is actually invalid.
            // Refresh and retry once with the new token.
            return $this->refreshThenVerify($account, $e);
        }
    }

    /**
     * Refresh the token, then verify with the new one.
     *
     * If either the refresh is rejected (4xx) or the verify that follows it hits
     * a token a concurrent refresh has already rotated — including the sub-commit
     * window where a lock-skipped refresh reloads a not-yet-persisted token —
     * reload and, when another process has since persisted a fresh access_token,
     * verify with that instead of giving up. X (and other providers that
     * single-use their refresh_token) would otherwise disconnect a still-usable
     * account whenever two refreshes race and one loses.
     *
     * @throws TokenExpiredException
     * @throws PlatformUnavailableException
     */
    private function refreshThenVerify(SocialAccount $account, ?TokenExpiredException $original = null): bool
    {
        $accessTokenBeforeRefresh = $account->access_token;

        try {
            $this->refreshToken($account);

            return $this->callVerifyEndpoint($account);
        } catch (TokenExpiredException $e) {
            $account->refresh();

            if ($account->access_token !== $accessTokenBeforeRefresh) {
                return $this->callVerifyEndpoint($account);
            }

            throw $original ?? $e;
        }
    }

    /**
     * Pull a token out of a refresh response, refusing to persist a blank one.
     *
     * TokenRefreshClient classifies on HTTP status alone, so a 200 carrying no
     * token would otherwise overwrite a credential that still works.
     *
     * @param  array<string, mixed>|null  $data
     *
     * @throws PlatformUnavailableException
     */
    private function rotatedTokenFrom(?array $data, string $key, string $current): string
    {
        $token = data_get($data, $key);

        // Blank, not just missing: data_get()'s own default lets an explicit
        // null through and overwrite.
        return blank($token) ? $current : (string) $token;
    }

    private function tokenFrom(?array $data, Platform $platform, string $key = 'access_token'): string
    {
        $token = data_get($data, $key);

        if (blank($token)) {
            throw new PlatformUnavailableException(
                "{$platform->label()} returned a successful refresh with no {$key}."
            );
        }

        return (string) $token;
    }

    private function refreshHttp(): PendingRequest
    {
        return Http::timeout(self::REFRESH_TIMEOUT_SECONDS)
            ->connectTimeout(self::REFRESH_CONNECT_TIMEOUT_SECONDS);
    }

    /**
     * Check the stored access token as it is, skipping the refresh-and-retry
     * ladder verify() runs — which would re-send a refresh_token the provider
     * just rejected, and on Bluesky re-run a rate-limited password re-auth.
     *
     * @throws TokenExpiredException if the access token itself is rejected
     * @throws PlatformUnavailableException if the platform is unreachable
     */
    public function verifyAccessToken(SocialAccount $account): bool
    {
        return $this->callVerifyEndpoint($account);
    }

    /**
     * @throws TokenExpiredException
     */
    private function callVerifyEndpoint(SocialAccount $account): bool
    {
        return match ($account->platform) {
            Platform::LinkedIn => $this->verifyLinkedIn($account),
            Platform::LinkedInPage => $this->verifyLinkedInPage($account),
            Platform::X => $this->verifyX($account),
            Platform::Instagram, Platform::InstagramFacebook => $this->verifyInstagram($account),
            Platform::Facebook => $this->verifyFacebook($account),
            Platform::Threads => $this->verifyThreads($account),
            Platform::TikTok => $this->verifyTikTok($account),
            Platform::YouTube => $this->verifyYouTube($account),
            Platform::Pinterest => $this->verifyPinterest($account),
            Platform::Bluesky => $this->verifyBluesky($account),
            Platform::Mastodon => $this->verifyMastodon($account),
            Platform::Telegram => $this->verifyTelegram($account),
            Platform::Discord => $this->verifyDiscord($account),
        };
    }

    /**
     * Refresh the account's token via the platform-specific OAuth flow.
     * Callers that want the smart "try access_token first" behavior should
     * use verify() instead. This method always attempts a refresh under
     * the per-account lock.
     *
     * @return bool whether a refresh actually ran — false means another
     *              process held the lock and this call proved nothing.
     *
     * @throws TokenExpiredException if refresh is rejected by the provider (4xx)
     * @throws PlatformUnavailableException if the platform is unreachable (5xx / network)
     */
    public function refreshToken(SocialAccount $account): bool
    {
        $lock = Cache::lock("token_refresh:{$account->id}", self::REFRESH_LOCK_SECONDS);

        if (! $lock->get()) {
            // Another process is already refreshing this token.
            $account->refresh();

            if ($account->is_token_expired) {
                // Returning false would hand the caller a token it knows is
                // dead; a publisher then posts with it, fails the post and
                // disconnects the account. Transient is the truth here.
                throw new PlatformUnavailableException(
                    "A {$account->platform->label()} token refresh is already in progress."
                );
            }

            return false;
        }

        try {
            if (! $account->platform->hasTokenRefreshFlow()) {
                // Page tokens, Mastodon and the shared bot tokens have
                // nothing per-account to refresh.
                return false;
            }

            match ($account->platform) {
                Platform::LinkedIn, Platform::LinkedInPage => $this->refreshLinkedInToken($account),
                Platform::X => $this->refreshXToken($account),
                Platform::Bluesky => $this->refreshBlueskyToken($account),
                Platform::YouTube => $this->refreshYouTubeToken($account),
                Platform::TikTok => $this->refreshTikTokToken($account),
                Platform::Pinterest => $this->refreshPinterestToken($account),
                Platform::Threads => $this->refreshThreadsToken($account),
                Platform::Instagram => $this->refreshInstagramToken($account),
            };

            return true;
        } finally {
            $lock->release();
        }
    }

    private function refreshLinkedInToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new TokenExpiredException("No refresh token available for {$account->platform->label()} account");
        }

        $response = TokenRefreshClient::for($account->platform)->send(fn () => $this->refreshHttp()->asForm()
            ->post(config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => config('services.linkedin.client_id'),
                'client_secret' => config('services.linkedin.client_secret'),
            ]));

        $data = $response->json();

        $account->update([
            'access_token' => $this->tokenFrom($data, $account->platform),
            'refresh_token' => $this->rotatedTokenFrom($data, 'refresh_token', $account->refresh_token),
            'token_expires_at' => data_get($data, 'expires_in') ? now()->addSeconds(data_get($data, 'expires_in')) : null,
        ]);

        $account->refresh();
    }

    private function refreshXToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new TokenExpiredException('No refresh token available for X account');
        }

        $response = TokenRefreshClient::for(Platform::X)->send(fn () => $this->refreshHttp()->asForm()
            ->withBasicAuth(config('services.x.client_id'), config('services.x.client_secret'))
            ->post(config('trypost.platforms.x.api').'/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]));

        $data = $response->json();

        $account->update([
            'access_token' => $this->tokenFrom($data, $account->platform),
            'refresh_token' => $this->rotatedTokenFrom($data, 'refresh_token', $account->refresh_token),
            'token_expires_at' => now()->addSeconds(data_get($data, 'expires_in', $account->platform->defaultTokenTtlSeconds())),
        ]);

        $account->refresh();
    }

    private function refreshBlueskyToken(SocialAccount $account): void
    {
        $service = $account->meta['service'] ?? config('trypost.platforms.bluesky.default_service');
        $client = TokenRefreshClient::for(Platform::Bluesky);

        try {
            $response = $client->send(fn () => $this->refreshHttp()->withToken($account->refresh_token)
                ->post("{$service}/xrpc/".BlueskyLexicon::REFRESH_SESSION));

            $data = $response->json();
            $account->update([
                'access_token' => $this->tokenFrom($data, $account->platform, 'accessJwt'),
                'refresh_token' => $this->tokenFrom($data, $account->platform, 'refreshJwt'),
                'token_expires_at' => now()->addHours(2),
            ]);

            $account->refresh();

            return;
        } catch (TokenExpiredException) {
            // refresh token was rejected (4xx) — fall back to re-auth below
        }

        if (isset($account->meta['password'])) {
            try {
                $reauth = $client->send(fn () => $this->refreshHttp()->post("{$service}/xrpc/".BlueskyLexicon::CREATE_SESSION, [
                    'identifier' => $account->meta['identifier'],
                    'password' => decrypt($account->meta['password']),
                ]));

                $data = $reauth->json();
                $account->update([
                    'access_token' => $this->tokenFrom($data, $account->platform, 'accessJwt'),
                    'refresh_token' => $this->tokenFrom($data, $account->platform, 'refreshJwt'),
                    'token_expires_at' => now()->addHours(2),
                ]);

                $account->refresh();

                return;
            } catch (TokenExpiredException) {
                // re-auth rejected with stored credentials — fall through
            }
        }

        throw new TokenExpiredException('Bluesky session expired');
    }

    private function refreshYouTubeToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new TokenExpiredException('No refresh token available for YouTube account');
        }

        $response = TokenRefreshClient::for(Platform::YouTube)->send(fn () => $this->refreshHttp()->asForm()
            ->post(config('trypost.platforms.youtube.oauth_api').'/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
            ]));

        $data = $response->json();

        $account->update([
            'access_token' => $this->tokenFrom($data, $account->platform),
            'token_expires_at' => data_get($data, 'expires_in') ? now()->addSeconds(data_get($data, 'expires_in')) : null,
        ]);

        $account->refresh();
    }

    private function refreshTikTokToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new TokenExpiredException('No refresh token available for TikTok account');
        }

        $response = TokenRefreshClient::for(Platform::TikTok)->send(fn () => $this->refreshHttp()->asForm()
            ->post(config('trypost.platforms.tiktok.api').'/oauth/token/', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_key' => config('services.tiktok.client_id'),
                'client_secret' => config('services.tiktok.client_secret'),
            ]));

        $data = $response->json();

        $account->update([
            'access_token' => $this->tokenFrom($data, $account->platform),
            'refresh_token' => $this->rotatedTokenFrom($data, 'refresh_token', $account->refresh_token),
            'token_expires_at' => data_get($data, 'expires_in') ? now()->addSeconds(data_get($data, 'expires_in')) : null,
        ]);

        $account->refresh();
    }

    private function refreshPinterestToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new TokenExpiredException('No refresh token available for Pinterest account');
        }

        $credentials = base64_encode(config('services.pinterest.client_id').':'.config('services.pinterest.client_secret'));

        $response = TokenRefreshClient::for(Platform::Pinterest)->send(fn () => $this->refreshHttp()->withHeaders([
            'Authorization' => "Basic {$credentials}",
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->asForm()->post(config('trypost.platforms.pinterest.api').'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]));

        $data = $response->json();

        $account->update([
            'access_token' => $this->tokenFrom($data, $account->platform),
            'refresh_token' => $this->rotatedTokenFrom($data, 'refresh_token', $account->refresh_token),
            'token_expires_at' => data_get($data, 'expires_in') ? now()->addSeconds(data_get($data, 'expires_in')) : null,
        ]);

        $account->refresh();
    }

    private function refreshThreadsToken(SocialAccount $account): void
    {
        // Threads uses long-lived tokens that can be refreshed
        $response = TokenRefreshClient::for(Platform::Threads)->send(
            fn () => $this->refreshHttp()->get(config('trypost.platforms.threads.auth_api').'/refresh_access_token', [
                'grant_type' => 'th_refresh_token',
                'access_token' => $account->access_token,
            ]),
            fn (?array $body) => ! GraphError::isTransient($body),
        );

        $data = $response->json();
        $newToken = $this->tokenFrom($data, $account->platform);

        $account->update([
            'access_token' => $newToken,
            'refresh_token' => $newToken,
            'token_expires_at' => now()->addSeconds(data_get($data, 'expires_in', $account->platform->defaultTokenTtlSeconds())),
        ]);

        $account->refresh();
    }

    private function refreshInstagramToken(SocialAccount $account): void
    {
        $response = TokenRefreshClient::for(Platform::Instagram)->send(
            fn () => $this->refreshHttp()->get(config('trypost.platforms.instagram.auth_api').'/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $account->access_token,
            ]),
            fn (?array $body) => ! GraphError::isTransient($body),
        );

        $data = $response->json();
        $newToken = $this->tokenFrom($data, $account->platform);

        $account->update([
            'access_token' => $newToken,
            'refresh_token' => $newToken,
            'token_expires_at' => now()->addSeconds(data_get($data, 'expires_in', $account->platform->defaultTokenTtlSeconds())),
        ]);

        $account->refresh();
    }

    private function verifyLinkedIn(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => '202601',
            ])
            ->get(config('trypost.platforms.linkedin.api').'/rest/userinfo');

        if (LinkedInPublishException::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException('LinkedIn access token is invalid or expired');
        }

        if ($response->successful()) {
            return true;
        }

        throw new PlatformUnavailableException(
            "{$account->platform->label()} verify failed ({$response->status()}).",
            $response->status(),
        );
    }

    private function verifyLinkedInPage(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'X-Restli-Protocol-Version' => '2.0.0',
                'LinkedIn-Version' => '202601',
            ])
            ->get(config('trypost.platforms.linkedin-page.api').'/rest/organizationAcls', [
                'q' => 'roleAssignee',
            ]);

        if (LinkedInPublishException::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException('LinkedIn Page access token is invalid or expired');
        }

        if ($response->successful()) {
            return true;
        }

        throw new PlatformUnavailableException(
            "{$account->platform->label()} verify failed ({$response->status()}).",
            $response->status(),
        );
    }

    private function verifyX(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->get(config('trypost.platforms.x.api').'/users/me');

        if (XPublishException::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException('X access token is invalid or expired');
        }

        if ($response->successful()) {
            return true;
        }

        throw new PlatformUnavailableException(
            "{$account->platform->label()} verify failed ({$response->status()}).",
            $response->status(),
        );
    }

    private function verifyInstagram(SocialAccount $account): bool
    {
        // Basic Instagram tokens hit graph.instagram.com; Instagram via
        // Facebook Business uses a Facebook Page token, which only validates
        // against graph.facebook.com — using the wrong endpoint produces a
        // false-positive "token expired".
        $baseUrl = $account->platform->instagramGraphBaseUrl();

        $response = Http::get("{$baseUrl}/me", [
            'fields' => 'id,username',
            'access_token' => $account->access_token,
        ]);

        if ($response->successful()) {
            return true;
        }

        throw GraphError::classifyVerifyFailure($response, 'Instagram');
    }

    private function verifyFacebook(SocialAccount $account): bool
    {
        $response = Http::get(config('trypost.platforms.facebook.graph_api').'/me', [
            'fields' => 'id,name',
            'access_token' => $account->access_token,
        ]);

        if ($response->successful()) {
            return true;
        }

        throw GraphError::classifyVerifyFailure($response, 'Facebook');
    }

    private function verifyThreads(SocialAccount $account): bool
    {
        $response = Http::get(config('trypost.platforms.threads.graph_api').'/me', [
            'fields' => 'id,username',
            'access_token' => $account->access_token,
        ]);

        if ($response->successful()) {
            return true;
        }

        throw GraphError::classifyVerifyFailure($response, 'Threads');
    }

    private function verifyTikTok(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->get(config('trypost.platforms.tiktok.api').'/user/info/', [
                'fields' => 'open_id,display_name',
            ]);

        // 401 here (unlike a publish-time 401, which TikTok also returns for
        // scope_not_authorized/scope_permission_missed) is unambiguous: this
        // endpoint only needs the always-granted user.info.basic scope, so a
        // 401 can't be a scope gap. See TikTokPublishException::isConfirmedDeadToken().
        if (TikTokPublishException::isConfirmedDeadToken($response) || $response->status() === 401) {
            throw new TokenExpiredException('TikTok access token is invalid or expired');
        }

        if ($response->successful()) {
            return true;
        }

        throw new PlatformUnavailableException(
            "{$account->platform->label()} verify failed ({$response->status()}).",
            $response->status(),
        );
    }

    private function verifyYouTube(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->get(config('trypost.platforms.youtube.data_api').'/channels', [
                'part' => 'id',
                'mine' => 'true',
            ]);

        if (YouTubePublishException::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException('YouTube access token is invalid or expired');
        }

        if ($response->successful()) {
            return true;
        }

        throw new PlatformUnavailableException(
            "{$account->platform->label()} verify failed ({$response->status()}).",
            $response->status(),
        );
    }

    private function verifyPinterest(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->get(config('trypost.platforms.pinterest.api').'/user_account');

        if (PinterestPublishException::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException('Pinterest access token is invalid or expired');
        }

        if ($response->successful()) {
            return true;
        }

        throw new PlatformUnavailableException(
            "{$account->platform->label()} verify failed ({$response->status()}).",
            $response->status(),
        );
    }

    private function verifyBluesky(SocialAccount $account): bool
    {
        $service = $account->meta['service'] ?? config('trypost.platforms.bluesky.default_service');

        $response = Http::withToken($account->access_token)
            ->get("{$service}/xrpc/".BlueskyLexicon::GET_PROFILE, [
                'actor' => $account->platform_user_id,
            ]);

        if (BlueskyPublishException::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException('Bluesky access token is invalid or expired');
        }

        if ($response->successful()) {
            return true;
        }

        throw new PlatformUnavailableException(
            "{$account->platform->label()} verify failed ({$response->status()}).",
            $response->status(),
        );
    }

    private function verifyTelegram(SocialAccount $account): bool
    {
        // getChat succeeds only while the bot can still reach the chat.
        $response = Http::get(TelegramApi::endpoint('getChat'), [
            'chat_id' => data_get($account->meta, 'chat_id'),
        ]);

        if ($response->successful() && data_get($response->json(), 'ok') === true) {
            return true;
        }

        if (TelegramPublishException::isConfirmedDeadChat($response)) {
            throw new TokenExpiredException('Telegram bot no longer has access to the chat');
        }

        throw new PlatformUnavailableException("Telegram getChat failed ({$response->status()}).", $response->status());
    }

    private function verifyDiscord(SocialAccount $account): bool
    {
        // The guild endpoint succeeds only while the bot is still a member.
        $response = app(DiscordClient::class)->getGuild((string) $account->platform_user_id);

        if ($response->successful()) {
            return true;
        }

        if (DiscordPublishException::isConfirmedDeadGuild($response)) {
            throw new TokenExpiredException('Discord bot no longer has access to the guild');
        }

        throw new PlatformUnavailableException("Discord guild lookup failed ({$response->status()}).", $response->status());
    }

    private function verifyMastodon(SocialAccount $account): bool
    {
        $instance = $account->meta['instance'] ?? config('trypost.platforms.mastodon.default_instance');

        $response = Http::withToken($account->access_token)
            ->get("{$instance}/api/v1/accounts/verify_credentials");

        // 403 here (unlike a publish-time 403 on the write-scoped /statuses
        // endpoint) means even read access is gone — verify_credentials is
        // the lowest-privilege endpoint every authorized app token can
        // reach, so a 403 confirms total revocation, not a scope gap. See
        // MastodonPublishException::isConfirmedDeadToken().
        if (MastodonPublishException::isConfirmedDeadToken($response) || $response->status() === 403) {
            throw new TokenExpiredException('Mastodon access token is invalid or expired');
        }

        if ($response->successful()) {
            return true;
        }

        throw new PlatformUnavailableException(
            "{$account->platform->label()} verify failed ({$response->status()}).",
            $response->status(),
        );
    }
}
