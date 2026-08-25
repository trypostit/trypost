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

class PinterestController extends SocialController
{
    protected string $driver = 'pinterest';

    protected SocialPlatform $platform = SocialPlatform::Pinterest;

    protected array $scopes = [
        'boards:read',
        'boards:write',
        'pins:read',
        'pins:write',
        'user_accounts:read',
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
            $socialUser = Socialite::driver($this->driver)->user();

            $avatarPath = uploadFromUrl($socialUser->getAvatar());
            $reconnect = $this->reconnectAccount($workspace);

            SocialAccount::connectIdentity(
                $workspace,
                $this->platform,
                $socialUser->getId(),
                [
                    'username' => $socialUser->getNickname(),
                    'display_name' => $socialUser->getName() ?? $socialUser->getNickname(),
                    'avatar_url' => $avatarPath,
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : now()->addDays(30),
                    // Pinterest returns scopes space-joined but Socialite doesn't split them, so re-split here.
                    'scopes' => explode(' ', implode(' ', $socialUser->approvedScopes)),
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
            Log::error('Pinterest OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }
}
