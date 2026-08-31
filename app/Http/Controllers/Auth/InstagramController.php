<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
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

        $this->rememberConnectSession($request, $workspace);

        $url = Socialite::driver($this->driver)
            ->scopes($this->scopes)
            ->redirect()
            ->getTargetUrl();

        return Inertia::location($url);
    }

    public function callback(Request $request): InertiaResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            // Instagram API with Instagram Login returns the user directly
            $avatarPath = $socialUser->getAvatar() ? uploadFromUrl($socialUser->getAvatar()) : null;

            // Calculate token expiration (long-lived tokens last 60 days)
            $expiresIn = $socialUser->expiresIn ?? $this->platform->defaultTokenTtlSeconds();
            $tokenExpiresAt = now()->addSeconds($expiresIn);
            $reconnect = $this->reconnectAccount($workspace);

            // Instagram Login returns a single identity, but it shares a network
            // with the Facebook variant: without this the same account could be
            // seated twice, once under each platform.
            if ($this->filterConnectableIdentities($workspace, [['id' => $socialUser->getId()]], 'id', $reconnect) === []) {
                return $this->noConnectableIdentities($reconnect, 'wrong_account');
            }

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
                    'token_expires_at' => $tokenExpiresAt,
                    'scopes' => $this->scopes,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'account_type' => $socialUser->user['account_type'] ?? null,
                    ],
                ],
                $reconnect,
            );

            return $this->connectedCallback($reconnect);
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Instagram OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }
}
