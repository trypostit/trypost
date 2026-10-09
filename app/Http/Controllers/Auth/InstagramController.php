<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
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
        // first comment after publish (FirstCommentPoster)
        'instagram_business_manage_comments',
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

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            $scopes = $this->reportedScopes($socialUser->approvedScopes, $this->scopes);

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
                        'token_expires_at' => now()->addSeconds($socialUser->expiresIn ?? $this->platform->defaultTokenTtlSeconds()),
                        'scopes' => $scopes,
                        'meta' => [
                            'account_type' => data_get($socialUser->user, 'account_type'),
                        ],
                    ],
                ),
            ], $this->reconnectAccount($workspace));
        } catch (Exception $e) {
            Log::error('Instagram OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }
}
