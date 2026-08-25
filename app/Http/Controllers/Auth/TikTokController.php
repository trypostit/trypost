<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class TikTokController extends SocialController
{
    protected string $driver = 'tiktok';

    protected SocialPlatform $platform = SocialPlatform::TikTok;

    protected array $scopes = [
        'user.info.basic',
        'user.info.profile',
        'user.info.stats',
        'video.publish',
        'video.upload',
        'video.list',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return $this->redirectToProvider($request, $this->driver, $this->scopes);
    }

    public function callback(Request $request): InertiaResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)
                ->scopes($this->scopes)
                ->user();

            // TikTok returns username via getNickname() when user.info.profile scope is included
            $username = $socialUser->getNickname();
            $avatarPath = uploadFromUrl($socialUser->getAvatar());
            $reconnect = $this->reconnectAccount($workspace);

            SocialAccount::connectIdentity(
                $workspace,
                $this->platform,
                $socialUser->getId(),
                [
                    'username' => $username,
                    'display_name' => $socialUser->getName(),
                    'avatar_url' => $avatarPath,
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                    'scopes' => $socialUser->approvedScopes ?? null,
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
            Log::error('TikTok OAuth Error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }
}
