<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
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

    public function callback(Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        $reconnect = $this->reconnectAccount($workspace);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            $channels = $this->fetchChannels($socialUser->token);

            if (empty($channels)) {
                return $this->popupCallback(false, __('accounts.popup_callback.no_youtube_channels'), $this->platform->value);
            }

            $channels = $this->filterConnectableIdentities($workspace, $channels, 'id', $reconnect);

            if (empty($channels)) {
                return $this->noConnectableIdentities($reconnect, 'channel_not_found');
            }

            // Google's own delegation screen already made the user pick which
            // channel this authorization is for, so channels?mine=true answers
            // with that one. More than one only arrives if that ever changes.
            if (count($channels) > 1) {
                Log::warning('YouTube returned more than one channel for a delegated token', [
                    'channel_ids' => array_column($channels, 'id'),
                ]);
            }

            $channel = $channels[0];
            $avatarPath = uploadFromUrl(data_get($channel, 'thumbnail'));

            SocialAccount::connectIdentity(
                $workspace,
                $this->platform,
                (string) data_get($channel, 'id'),
                [
                    'username' => ltrim(data_get($channel, 'custom_url', data_get($channel, 'id')), '@'),
                    'display_name' => data_get($channel, 'title'),
                    'avatar_url' => $avatarPath,
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                    'scopes' => $this->scopes,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'channel_id' => data_get($channel, 'id'),
                        'google_user_id' => $socialUser->getId(),
                    ],
                ],
                $reconnect,
            );

            return $this->connectedCallback($reconnect);
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('YouTube OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    private function redirectToGoogle(): Response
    {
        return Inertia::location(
            Socialite::driver($this->driver)
                ->scopes($this->scopes)
                ->with([
                    'access_type' => 'offline',
                    'prompt' => 'consent',
                    'include_granted_scopes' => 'true',
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
        } catch (\Exception $e) {
            Log::error('YouTube channels fetch error', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
