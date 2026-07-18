<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Social\Discord\DiscordClient;
use App\Services\Social\Meta\GraphError;
use App\Services\Social\Telegram\TelegramApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class ConnectionVerifier
{
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
        // rotating refresh_tokens unnecessarily — many providers (X v2,
        // LinkedIn, etc.) invalidate the previous refresh_token on each
        // refresh, so proactive refreshes during races cause false-positive
        // disconnects even though the access_token still works fine.
        if ($account->is_token_expired) {
            return $this->refreshThenVerify($account);
        }

        try {
            return $this->callVerifyEndpoint($account);
        } catch (TokenExpiredException $e) {
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
     * @throws TokenExpiredException if refresh is rejected by the provider (4xx)
     * @throws PlatformUnavailableException if the platform is unreachable (5xx / network)
     */
    public function refreshToken(SocialAccount $account): void
    {
        $lock = Cache::lock("token_refresh:{$account->id}", 30);

        if (! $lock->get()) {
            // Another process is already refreshing this token
            $account->refresh();

            return;
        }

        try {
            match ($account->platform) {
                Platform::LinkedIn, Platform::LinkedInPage => $this->refreshLinkedInToken($account),
                Platform::X => $this->refreshXToken($account),
                Platform::Bluesky => $this->refreshBlueskyToken($account),
                Platform::YouTube => $this->refreshYouTubeToken($account),
                Platform::TikTok => $this->refreshTikTokToken($account),
                Platform::Pinterest => $this->refreshPinterestToken($account),
                Platform::Threads => $this->refreshThreadsToken($account),
                Platform::Instagram => $this->refreshInstagramToken($account),
                // Facebook / InstagramFacebook use Page tokens that don't expire.
                // Mastodon tokens don't expire either.
                default => null,
            };
        } finally {
            $lock->release();
        }
    }

    private function refreshLinkedInToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new TokenExpiredException("No refresh token available for {$account->platform->label()} account");
        }

        $response = TokenRefreshClient::for($account->platform)->send(fn () => Http::asForm()
            ->post(config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => config('services.linkedin.client_id'),
                'client_secret' => config('services.linkedin.client_secret'),
            ]));

        $data = $response->json();

        $account->update([
            'access_token' => data_get($data, 'access_token'),
            'refresh_token' => data_get($data, 'refresh_token', $account->refresh_token),
            'token_expires_at' => data_get($data, 'expires_in') ? now()->addSeconds(data_get($data, 'expires_in')) : null,
        ]);

        $account->refresh();
    }

    private function refreshXToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new TokenExpiredException('No refresh token available for X account');
        }

        $response = TokenRefreshClient::for(Platform::X)->send(fn () => Http::asForm()
            ->withBasicAuth(config('services.x.client_id'), config('services.x.client_secret'))
            ->post(config('trypost.platforms.x.api').'/oauth2/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
            ]));

        $data = $response->json();

        $account->update([
            'access_token' => data_get($data, 'access_token'),
            'refresh_token' => data_get($data, 'refresh_token', $account->refresh_token),
            'token_expires_at' => now()->addSeconds(data_get($data, 'expires_in', $account->platform->defaultTokenTtlSeconds())),
        ]);

        $account->refresh();
    }

    private function refreshBlueskyToken(SocialAccount $account): void
    {
        $service = $account->meta['service'] ?? config('trypost.platforms.bluesky.default_service');
        $client = TokenRefreshClient::for(Platform::Bluesky);

        try {
            $response = $client->send(fn () => Http::withToken($account->refresh_token)
                ->post("{$service}/xrpc/".BlueskyLexicon::REFRESH_SESSION));

            $data = $response->json();
            $account->update([
                'access_token' => data_get($data, 'accessJwt'),
                'refresh_token' => data_get($data, 'refreshJwt'),
                'token_expires_at' => now()->addHours(2),
            ]);

            $account->refresh();

            return;
        } catch (TokenExpiredException) {
            // refresh token was rejected (4xx) — fall back to re-auth below
        }

        if (isset($account->meta['password'])) {
            try {
                $reauth = $client->send(fn () => Http::post("{$service}/xrpc/".BlueskyLexicon::CREATE_SESSION, [
                    'identifier' => $account->meta['identifier'],
                    'password' => decrypt($account->meta['password']),
                ]));

                $data = $reauth->json();
                $account->update([
                    'access_token' => data_get($data, 'accessJwt'),
                    'refresh_token' => data_get($data, 'refreshJwt'),
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

        $response = TokenRefreshClient::for(Platform::YouTube)->send(fn () => Http::asForm()
            ->post(config('trypost.platforms.youtube.oauth_api').'/token', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
            ]));

        $data = $response->json();

        $account->update([
            'access_token' => data_get($data, 'access_token'),
            'token_expires_at' => data_get($data, 'expires_in') ? now()->addSeconds(data_get($data, 'expires_in')) : null,
        ]);

        $account->refresh();
    }

    private function refreshTikTokToken(SocialAccount $account): void
    {
        if (! $account->refresh_token) {
            throw new TokenExpiredException('No refresh token available for TikTok account');
        }

        $response = TokenRefreshClient::for(Platform::TikTok)->send(fn () => Http::asForm()
            ->post(config('trypost.platforms.tiktok.api').'/oauth/token/', [
                'grant_type' => 'refresh_token',
                'refresh_token' => $account->refresh_token,
                'client_key' => config('services.tiktok.client_id'),
                'client_secret' => config('services.tiktok.client_secret'),
            ]));

        $data = $response->json();

        $account->update([
            'access_token' => data_get($data, 'access_token'),
            'refresh_token' => data_get($data, 'refresh_token', $account->refresh_token),
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

        $response = TokenRefreshClient::for(Platform::Pinterest)->send(fn () => Http::withHeaders([
            'Authorization' => "Basic {$credentials}",
            'Content-Type' => 'application/x-www-form-urlencoded',
        ])->asForm()->post(config('trypost.platforms.pinterest.api').'/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $account->refresh_token,
        ]));

        $data = $response->json();

        $account->update([
            'access_token' => data_get($data, 'access_token'),
            'refresh_token' => data_get($data, 'refresh_token', $account->refresh_token),
            'token_expires_at' => data_get($data, 'expires_in') ? now()->addSeconds(data_get($data, 'expires_in')) : null,
        ]);

        $account->refresh();
    }

    private function refreshThreadsToken(SocialAccount $account): void
    {
        // Threads uses long-lived tokens that can be refreshed
        $response = TokenRefreshClient::for(Platform::Threads)->send(
            fn () => Http::get(config('trypost.platforms.threads.auth_api').'/refresh_access_token', [
                'grant_type' => 'th_refresh_token',
                'access_token' => $account->access_token,
            ]),
            GraphError::indicatesInvalidToken(...),
        );

        $data = $response->json();
        $newToken = data_get($data, 'access_token');

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
            fn () => Http::get(config('trypost.platforms.instagram.auth_api').'/refresh_access_token', [
                'grant_type' => 'ig_refresh_token',
                'access_token' => $account->access_token,
            ]),
            GraphError::indicatesInvalidToken(...),
        );

        $data = $response->json();
        $newToken = data_get($data, 'access_token');

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

        if ($response->status() === 401) {
            throw new TokenExpiredException('LinkedIn access token is invalid or expired');
        }

        return $response->successful();
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

        if ($response->status() === 401) {
            throw new TokenExpiredException('LinkedIn Page access token is invalid or expired');
        }

        return $response->successful();
    }

    private function verifyX(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->get(config('trypost.platforms.x.api').'/users/me');

        if ($response->status() === 401) {
            throw new TokenExpiredException('X access token is invalid or expired');
        }

        return $response->successful();
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

        $body = $response->json() ?? [];

        if (GraphError::indicatesInvalidToken($body)) {
            throw new TokenExpiredException('Instagram access token is invalid or expired');
        }

        return $response->successful();
    }

    private function verifyFacebook(SocialAccount $account): bool
    {
        $response = Http::get(config('trypost.platforms.facebook.graph_api').'/me', [
            'fields' => 'id,name',
            'access_token' => $account->access_token,
        ]);

        $body = $response->json() ?? [];

        if (GraphError::indicatesInvalidToken($body)) {
            throw new TokenExpiredException('Facebook access token is invalid or expired');
        }

        return $response->successful();
    }

    private function verifyThreads(SocialAccount $account): bool
    {
        $response = Http::get(config('trypost.platforms.threads.graph_api').'/me', [
            'fields' => 'id,username',
            'access_token' => $account->access_token,
        ]);

        $body = $response->json() ?? [];

        if (GraphError::indicatesInvalidToken($body)) {
            throw new TokenExpiredException('Threads access token is invalid or expired');
        }

        return $response->successful();
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

        $body = $response->json() ?? [];
        $errorCode = $body['error']['code'] ?? null;

        if ($response->status() === 401 || in_array($errorCode, ['access_token_invalid', 'access_token_expired', 10001, 10002])) {
            throw new TokenExpiredException('TikTok access token is invalid or expired');
        }

        return $response->successful();
    }

    private function verifyYouTube(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->get(config('trypost.platforms.youtube.data_api').'/channels', [
                'part' => 'id',
                'mine' => 'true',
            ]);

        if ($response->status() === 401) {
            throw new TokenExpiredException('YouTube access token is invalid or expired');
        }

        return $response->successful();
    }

    private function verifyPinterest(SocialAccount $account): bool
    {
        $response = Http::withToken($account->access_token)
            ->get(config('trypost.platforms.pinterest.api').'/user_account');

        if ($response->status() === 401) {
            throw new TokenExpiredException('Pinterest access token is invalid or expired');
        }

        return $response->successful();
    }

    private function verifyBluesky(SocialAccount $account): bool
    {
        $service = $account->meta['service'] ?? config('trypost.platforms.bluesky.default_service');

        $response = Http::withToken($account->access_token)
            ->get("{$service}/xrpc/".BlueskyLexicon::GET_PROFILE, [
                'actor' => $account->platform_user_id,
            ]);

        $body = $response->json() ?? [];
        $error = $body['error'] ?? null;

        if ($error === 'ExpiredToken' || $error === 'InvalidToken') {
            throw new TokenExpiredException('Bluesky access token is invalid or expired');
        }

        return $response->successful();
    }

    private function verifyTelegram(SocialAccount $account): bool
    {
        // getChat succeeds only while the bot can still reach the chat.
        $response = Http::get(TelegramApi::endpoint('getChat'), [
            'chat_id' => data_get($account->meta, 'chat_id'),
        ]);

        return $response->successful() && data_get($response->json(), 'ok') === true;
    }

    private function verifyDiscord(SocialAccount $account): bool
    {
        // The guild endpoint succeeds only while the bot is still a member.
        return app(DiscordClient::class)->getGuild((string) $account->platform_user_id)->successful();
    }

    private function verifyMastodon(SocialAccount $account): bool
    {
        $instance = $account->meta['instance'] ?? config('trypost.platforms.mastodon.default_instance');

        $response = Http::withToken($account->access_token)
            ->get("{$instance}/api/v1/accounts/verify_credentials");

        if ($response->status() === 401 || $response->status() === 403) {
            throw new TokenExpiredException('Mastodon access token is invalid or expired');
        }

        return $response->successful();
    }
}
