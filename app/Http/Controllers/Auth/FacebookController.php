<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Services\Social\Meta\ManagedPages;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class FacebookController extends MetaController
{
    protected string $pageFields = 'id,name,username,picture{url},access_token';

    protected string $noPagesKey = 'no_facebook_pages';

    protected SocialPlatform $platform = SocialPlatform::Facebook;

    protected array $scopes = [
        'public_profile',
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'read_insights',
        'business_management',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        $driver = Socialite::driver($this->driver)
            ->usingGraphVersion($this->graphVersion())
            ->setScopes($this->requestedScopes());

        $driver = $this->switchingAccount($request)
            ? $driver->with(['auth_type' => self::SWITCH_ACCOUNT_AUTH_TYPE])
            : $driver->reRequest();

        return Inertia::location($driver->redirect()->getTargetUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($this->driver)->usingGraphVersion($this->graphVersion())->user();

            $this->touchProfile($socialUser->token);

            $granted = $this->grantedScopes($socialUser->token);

            if ($granted instanceof RedirectResponse) {
                return $granted;
            }

            $walk = ManagedPages::forUser($this->graphApi(), $socialUser->token, $this->pageFields, $granted, $this->deadline());
            $listed = $this->toPageCards($walk->pages);
            $pages = ManagedPages::publishable($listed);

            if (empty($pages)) {
                return $this->noPagesOnOffer($walk, $listed);
            }

            return $this->offerIdentities($workspace, array_map(fn (array $page): array => PendingConnection::identity(
                $this->platform,
                (string) data_get($page, 'id'),
                data_get($page, 'name'),
                data_get($page, 'username'),
                data_get($page, 'picture'),
                $this->platform->identityType()->value,
                [
                    'username' => data_get($page, 'username'),
                    'display_name' => data_get($page, 'name'),
                    'access_token' => data_get($page, 'access_token'),
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'scopes' => $granted,
                    'meta' => [
                        'page_id' => data_get($page, 'id'),
                        'user_id' => $socialUser->getId(),
                        'user_token' => $socialUser->token,
                    ],
                ],
            ), $pages), $this->reconnectAccount($workspace), 'page_not_found', $walk->complete);
        } catch (Exception $e) {
            Log::error('Facebook OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function toPageCards(array $pages): array
    {
        return collect($pages)->map(fn (array $page) => [
            'id' => data_get($page, 'id'),
            'name' => data_get($page, 'name'),
            'username' => data_get($page, 'username'),
            'picture' => data_get($page, 'picture.data.url'),
            'access_token' => data_get($page, 'access_token'),
        ])->all();
    }

    private function graphVersion(): string
    {
        return Uri::of($this->graphApi())->path();
    }
}
