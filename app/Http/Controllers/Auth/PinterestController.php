<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class PinterestController extends SocialController
{
    protected string $driver = 'pinterest';

    protected SocialPlatform $platform = SocialPlatform::Pinterest;

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return $this->redirectToProvider($request, $this->driver);
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            $scopes = $this->reportedScopes($socialUser->approvedScopes);

            return $this->refusalForMissingPublishScopes($scopes) ?? $this->offerIdentities($workspace, [
                PendingConnection::identity(
                    $this->platform,
                    (string) $socialUser->getId(),
                    $socialUser->getName() ?? $socialUser->getNickname(),
                    $socialUser->getNickname(),
                    $socialUser->getAvatar(),
                    $this->platform->identityType()->value,
                    [
                        'username' => $socialUser->getNickname(),
                        'display_name' => $socialUser->getName() ?? $socialUser->getNickname(),
                        'access_token' => $socialUser->token,
                        'refresh_token' => $socialUser->refreshToken,
                        'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : now()->addDays(30),
                        'scopes' => $scopes,
                    ],
                ),
            ], $this->reconnectAccount($workspace));
        } catch (Exception $e) {
            Log::error('Pinterest OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }
}
