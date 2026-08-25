<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\ConnectPopupException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
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

        $this->rememberConnectSession($request, $workspace);

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
        $savedState = session('threads_oauth_state');
        session()->forget('threads_oauth_state');
        $workspace = $this->connectWorkspace($request);

        if ($request->state !== $savedState) {
            throw new ConnectPopupException('invalid_state', $this->platform);
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
            $reconnect = $this->reconnectAccount($workspace);

            SocialAccount::connectIdentity(
                $workspace,
                $this->platform,
                (string) data_get($profile, 'id'),
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
                $reconnect,
            );

            return $this->connectedCallback($reconnect);
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Threads OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }
}
