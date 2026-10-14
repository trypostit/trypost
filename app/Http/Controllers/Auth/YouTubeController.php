<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class YouTubeController extends SocialController
{
    protected string $driver = 'google';

    protected SocialPlatform $platform = SocialPlatform::YouTube;

    protected array $scopes = [
        'https://www.googleapis.com/auth/youtube.upload',
        'https://www.googleapis.com/auth/youtube.readonly',
        'https://www.googleapis.com/auth/youtube.force-ssl',
        'https://www.googleapis.com/auth/yt-analytics.readonly',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        return $this->redirectToGoogle();
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            $scopes = $this->reportedScopes($socialUser->approvedScopes, $this->scopes);
            $refusal = $this->refusalForMissingPublishScopes($scopes);

            if ($refusal !== null) {
                return $refusal;
            }

            $channels = $this->fetchChannels($socialUser->token);

            if (empty($channels)) {
                return $this->failConnection('no_youtube_channels');
            }

            return $this->offerIdentities($workspace, array_map(fn (array $channel): array => PendingConnection::identity(
                $this->platform,
                (string) data_get($channel, 'id'),
                data_get($channel, 'title'),
                data_get($channel, 'custom_url'),
                data_get($channel, 'thumbnail'),
                $this->platform->identityType()->value,
                [
                    'username' => ltrim((string) data_get($channel, 'custom_url', data_get($channel, 'id')), '@'),
                    'display_name' => data_get($channel, 'title'),
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                    'scopes' => $scopes,
                    'meta' => [
                        'channel_id' => data_get($channel, 'id'),
                        'google_user_id' => $socialUser->getId(),
                    ],
                ],
            ), $channels), $this->reconnectAccount($workspace), 'channel_not_found');
        } catch (Exception $e) {
            Log::error('YouTube OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }

    private function redirectToGoogle(): Response
    {
        return Inertia::location(
            Socialite::driver($this->driver)
                ->scopes($this->scopes)
                ->with([
                    'access_type' => 'offline',
                    'prompt' => 'select_account consent',
                ])
                ->redirect()
                ->getTargetUrl()
        );
    }

    private function fetchChannels(string $accessToken): array
    {
        try {
            $response = Http::withToken($accessToken)
                ->get(config('trypost.platforms.youtube.data_api').'/channels', [
                    'part' => 'snippet,contentDetails,statistics',
                    'mine' => 'true',
                ]);

            if ($response->failed()) {
                Log::error('YouTube channels fetch failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return [];
            }

            $data = $response->json();

            return collect(data_get($data, 'items', []))->map(fn ($channel) => [
                'id' => data_get($channel, 'id'),
                'title' => data_get($channel, 'snippet.title'),
                'description' => data_get($channel, 'snippet.description', ''),
                'thumbnail' => data_get($channel, 'snippet.thumbnails.default.url'),
                'custom_url' => data_get($channel, 'snippet.customUrl'),
                'subscriber_count' => data_get($channel, 'statistics.subscriberCount', 0),
            ])->toArray();
        } catch (Exception $e) {
            Log::error('YouTube channels fetch error', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
