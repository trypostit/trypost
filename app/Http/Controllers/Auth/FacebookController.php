<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\ConnectPopupException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Services\Social\Meta\GraphPaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class FacebookController extends SocialController
{
    protected string $driver = 'facebook';

    protected SocialPlatform $platform = SocialPlatform::Facebook;

    protected array $scopes = [
        'public_profile',
        'pages_show_list',
        'pages_read_engagement',
        'pages_manage_posts',
        'read_insights',
        // Needed for the Business Manager fallback in fetchPages() - /me/accounts
        // omits Pages under the New Pages Experience model, and reaching those
        // via /me/businesses + owned_pages requires this scope.
        'business_management',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        return Inertia::location(
            Socialite::driver($this->driver)
                ->usingGraphVersion($this->graphVersion())
                ->setScopes($this->scopes)
                ->redirect()
                ->getTargetUrl()
        );
    }

    public function callback(Request $request): InertiaResponse|RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        $reconnect = $this->reconnectAccount($workspace);

        try {
            $socialUser = Socialite::driver($this->driver)->usingGraphVersion($this->graphVersion())->user();

            // Trigger public_profile and pages_show_list API calls
            // These calls are needed for Meta app review permission verification
            Http::get(config('trypost.platforms.facebook.graph_api').'/me', [
                'fields' => 'id,name',
                'access_token' => $socialUser->token,
            ]);

            $pages = $this->fetchPages($socialUser->token);

            if (empty($pages)) {
                return $this->popupCallback(false, __('accounts.popup_callback.no_facebook_pages'), $this->platform->value);
            }

            $pages = $this->filterConnectableIdentities($workspace, $pages, 'id', $reconnect);

            if (empty($pages)) {
                return $this->noConnectableIdentities($reconnect, 'page_not_found');
            }

            // If only one page, connect directly
            if (count($pages) === 1) {
                $page = $pages[0];
                $avatarPath = uploadFromUrl(data_get($page, 'picture'));

                SocialAccount::connectIdentity(
                    $workspace,
                    $this->platform,
                    (string) data_get($page, 'id'),
                    [
                        'username' => data_get($page, 'username', null),
                        'display_name' => data_get($page, 'name'),
                        'avatar_url' => $avatarPath,
                        'access_token' => data_get($page, 'access_token'),
                        'refresh_token' => null,
                        'token_expires_at' => null,
                        'scopes' => $this->scopes,
                        'status' => Status::Connected,
                        'error_message' => null,
                        'disconnected_at' => null,
                        'meta' => [
                            'page_id' => data_get($page, 'id'),
                            'user_id' => $socialUser->getId(),
                            'user_token' => $socialUser->token,
                        ],
                    ],
                    $reconnect,
                );

                return $this->connectedCallback($reconnect);
            }

            // Multiple pages - store data and show selection
            session([
                'facebook_oauth' => [
                    'user_token' => $socialUser->token,
                    'user_id' => $socialUser->getId(),
                    'pages' => $pages,
                    'reconnect_id' => $reconnect?->id,
                ],
            ]);

            return redirect()->route('app.social.facebook.select-page');
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Facebook OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    public function selectPage(Request $request): InertiaResponse
    {
        $oauthData = session('facebook_oauth');

        if (! $oauthData) {
            throw new ConnectPopupException('session_expired', $this->platform);
        }

        $workspace = $this->connectWorkspace($request);

        $pages = collect(data_get($oauthData, 'pages'))
            ->map(fn ($page) => Arr::except($page, ['access_token']))
            ->toArray();

        return Inertia::render('accounts/FacebookPageSelect', [
            'workspace' => $workspace,
            'pages' => $pages,
        ]);
    }

    public function select(Request $request): InertiaResponse
    {
        $request->validate([
            'page_id' => 'required|string',
        ]);

        $oauthData = session('facebook_oauth');

        if (! $oauthData) {
            throw new ConnectPopupException('session_expired', $this->platform);
        }

        $workspace = $this->connectWorkspace($request);

        try {
            $selectedPage = collect(data_get($oauthData, 'pages'))->firstWhere('id', $request->page_id);

            if (! $selectedPage) {
                return $this->popupCallback(false, __('accounts.popup_callback.page_not_found'), $this->platform->value);
            }

            $avatarPath = uploadFromUrl(data_get($selectedPage, 'picture'));
            $reconnect = $this->reconnectAccount($workspace, data_get($oauthData, 'reconnect_id'));

            SocialAccount::connectIdentity(
                $workspace,
                $this->platform,
                (string) data_get($selectedPage, 'id'),
                [
                    'username' => data_get($selectedPage, 'username') ?? null,
                    'display_name' => data_get($selectedPage, 'name'),
                    'avatar_url' => $avatarPath,
                    'access_token' => data_get($selectedPage, 'access_token'),
                    'refresh_token' => null,
                    'token_expires_at' => null,
                    'scopes' => $this->scopes,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'page_id' => data_get($selectedPage, 'id'),
                        'user_id' => data_get($oauthData, 'user_id'),
                        'user_token' => data_get($oauthData, 'user_token'),
                    ],
                ],
                $reconnect,
            );

            session()->forget('facebook_oauth');

            return $this->connectedCallback($reconnect);
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Facebook page selection error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting_page'), $this->platform->value);
        }
    }

    private function fetchPages(string $userToken): array
    {
        $pages = GraphPaginator::all(
            config('trypost.platforms.facebook.graph_api').'/me/accounts',
            [
                'access_token' => $userToken,
                'fields' => 'id,name,username,picture{url},access_token',
                'limit' => 100,
            ],
        );

        // /me/accounts silently omits Pages that live under Meta's newer "New
        // Pages Experience" / Business Portfolio model, even when the token's
        // granular scopes show the Page was explicitly granted (confirmed via
        // Meta's own Access Token Debugger, 2026-08-16). Falling back through
        // Business Manager's owned_pages picks those up.
        if (empty($pages)) {
            $pages = $this->fetchPagesViaBusinessManager($userToken);
        }

        return collect($pages)->map(fn (array $page) => [
            'id' => data_get($page, 'id'),
            'name' => data_get($page, 'name'),
            'username' => data_get($page, 'username'),
            'picture' => data_get($page, 'picture.data.url'),
            'access_token' => data_get($page, 'access_token'),
        ])->all();
    }

    private function fetchPagesViaBusinessManager(string $userToken): array
    {
        $businesses = GraphPaginator::all(
            config('trypost.platforms.facebook.graph_api').'/me/businesses',
            [
                'access_token' => $userToken,
                'fields' => 'id',
                'limit' => 100,
            ],
        );

        $pages = collect();

        foreach ($businesses as $business) {
            $businessId = data_get($business, 'id');

            if (! $businessId) {
                continue;
            }

            foreach (['owned_pages', 'client_pages'] as $edge) {
                try {
                    $edgePages = GraphPaginator::all(
                        config('trypost.platforms.facebook.graph_api')."/{$businessId}/{$edge}",
                        [
                            'access_token' => $userToken,
                            'fields' => 'id,name,username,picture{url},access_token',
                            'limit' => 100,
                        ],
                    );
                } catch (\Throwable $e) {
                    Log::warning("Facebook Business Manager {$edge} lookup failed", [
                        'business_id' => $businessId,
                        'error' => $e->getMessage(),
                    ]);

                    continue;
                }

                $pages = $pages->concat($edgePages);
            }
        }

        return $pages->unique(fn (array $page) => data_get($page, 'id'))->values()->all();
    }

    private function graphVersion(): string
    {
        return Uri::of(config('trypost.platforms.facebook.graph_api'))->path();
    }
}
