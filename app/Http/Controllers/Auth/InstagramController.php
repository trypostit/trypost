<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class InstagramController extends SocialController
{
    protected string $driver = 'instagram';

    protected SocialPlatform $platform = SocialPlatform::Instagram;

    protected array $scopes = [
        'instagram_business_basic',
        'instagram_business_content_publish',
        'instagram_business_manage_insights',
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

        $url = Socialite::driver($this->driver)
            ->scopes($this->scopes)
            ->redirect()
            ->getTargetUrl();

        return Inertia::location($url);
    }

    public function callback(Request $request): InertiaResponse
    {
        $workspaceId = session('social_connect_workspace');

        if (! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            // Instagram API with Instagram Login returns the user directly
            $avatarPath = $socialUser->getAvatar() ? uploadFromUrl($socialUser->getAvatar()) : null;

            // Calculate token expiration (long-lived tokens last 60 days)
            $expiresIn = $socialUser->expiresIn ?? $this->platform->defaultTokenTtlSeconds();
            $tokenExpiresAt = now()->addSeconds($expiresIn);

            $workspace->socialAccounts()->updateOrCreate(
                [
                    'platform' => $this->platform->value,
                    'platform_user_id' => $socialUser->getId(),
                ],
                [
                    'username' => $socialUser->getNickname(),
                    'display_name' => $socialUser->getName() ?? $socialUser->getNickname(),
                    'avatar_url' => $avatarPath,
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => $tokenExpiresAt,
                    'scopes' => $this->scopes,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'account_type' => $socialUser->user['account_type'] ?? null,
                    ],
                ],
            );

            return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Instagram OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }
}
