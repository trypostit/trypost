<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\ConnectPopupException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Services\Social\Meta\ManagedPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class FacebookController extends MetaController
{
    protected string $pageFields = 'id,name,username,picture{url},access_token';

    protected string $noPagesKey = 'accounts.popup_callback.no_facebook_pages';

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

            $this->touchProfile($socialUser->token);

            $granted = $this->grantedScopes($socialUser->token);

            if ($granted instanceof InertiaResponse) {
                return $granted;
            }

            $walk = ManagedPages::forUser($this->graphApi(), $socialUser->token, $this->pageFields, $granted, $this->deadline());
            $listed = $this->toPageCards($walk->pages);
            $pages = ManagedPages::publishable($listed);

            if (empty($pages)) {
                return $this->noPagesOnOffer($walk, $listed);
            }

            $pages = $this->filterConnectableIdentities($workspace, $pages, 'id', $reconnect);

            if (empty($pages)) {
                return $this->noConnectableIdentities($reconnect, 'page_not_found', $walk->complete);
            }

            if (count($pages) === 1 && ($walk->complete || $reconnect !== null)) {
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
                        'scopes' => $granted,
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
                    'scopes' => $granted,
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
                    'scopes' => data_get($oauthData, 'scopes', $this->scopes),
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
