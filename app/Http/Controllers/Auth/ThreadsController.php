<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\Workspace;
use App\Services\Social\TokenRedactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;

class ThreadsController extends SocialController
{
    protected SocialPlatform $platform = SocialPlatform::Threads;

    protected array $scopes = [
        'threads_basic',
        'threads_content_publish',
        'threads_manage_insights',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        session([
            'social_connect_workspace' => $workspace->id,
            'social_reconnect_id' => null,
        ]);

        $state = bin2hex(random_bytes(16));
        session(['threads_oauth_state' => $state]);

        $params = http_build_query([
            'client_id' => config('services.threads.client_id'),
            'redirect_uri' => config('services.threads.redirect'),
            'scope' => implode(',', $this->scopes),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return Inertia::location("https://threads.net/oauth/authorize?{$params}");
    }

    public function callback(Request $request): InertiaResponse
    {
        $workspaceId = session('social_connect_workspace');
        $savedState = session('threads_oauth_state');

        if (! $workspaceId) {
            session()->forget(['threads_oauth_state', 'social_reconnect_id']);

            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        if ($request->state !== $savedState) {
            session()->forget(['threads_oauth_state', 'social_reconnect_id']);

            return $this->popupCallback(false, __('accounts.popup_callback.invalid_state'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            session()->forget(['threads_oauth_state', 'social_reconnect_id']);

            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        try {
            // Exchange code for short-lived token
            $tokenResponse = Http::asForm()->post(config('trypost.platforms.threads.auth_api').'/oauth/access_token', [
                'client_id' => config('services.threads.client_id'),
                'client_secret' => config('services.threads.client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.threads.redirect'),
                'code' => $request->code,
            ]);

            if ($tokenResponse->failed()) {
                Log::error('Threads token exchange failed', [
                    'status' => $tokenResponse->status(),
                    'body' => TokenRedactor::redact($tokenResponse->body()),
                ]);
                throw new \Exception('Failed to exchange token');
            }

            $tokenData = $tokenResponse->json();
            $shortLivedToken = $tokenData['access_token'];
            $userId = $tokenData['user_id'];

            // Exchange for long-lived token
            $longLivedResponse = Http::get(config('trypost.platforms.threads.auth_api').'/access_token', [
                'grant_type' => 'th_exchange_token',
                'client_secret' => config('services.threads.client_secret'),
                'access_token' => $shortLivedToken,
            ]);

            if ($longLivedResponse->failed()) {
                Log::error('Threads long-lived token exchange failed', [
                    'status' => $longLivedResponse->status(),
                    'body' => TokenRedactor::redact($longLivedResponse->body()),
                ]);
                throw new \Exception('Failed to exchange long-lived token');
            }

            $longLivedData = $longLivedResponse->json();
            $longLivedToken = $longLivedData['access_token'] ?? $shortLivedToken;
            $expiresIn = $longLivedData['expires_in'] ?? $this->platform->defaultTokenTtlSeconds();

            // Fetch user profile
            $profileResponse = Http::get(config('trypost.platforms.threads.graph_api')."/{$userId}", [
                'access_token' => $longLivedToken,
                'fields' => 'id,username,name,threads_profile_picture_url',
            ]);

            if ($profileResponse->failed()) {
                Log::error('Threads profile fetch failed', [
                    'body' => $profileResponse->body(),
                ]);
                throw new \Exception('Failed to fetch profile');
            }

            $profile = $profileResponse->json();
            $avatarPath = uploadFromUrl(data_get($profile, 'threads_profile_picture_url', null));

            $workspace->socialAccounts()->updateOrCreate(
                [
                    'platform' => $this->platform->value,
                    'platform_user_id' => data_get($profile, 'id'),
                ],
                [
                    'username' => data_get($profile, 'username'),
                    'display_name' => data_get($profile, 'name', data_get($profile, 'username')),
                    'avatar_url' => $avatarPath,
                    'access_token' => $longLivedToken,
                    'refresh_token' => null,
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'scopes' => $this->scopes,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                ],
            );

            session()->forget(['threads_oauth_state', 'social_reconnect_id']);

            return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Threads OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            session()->forget(['threads_oauth_state', 'social_reconnect_id']);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }
}
