<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\Workspace;
use App\Services\Social\Meta\GraphPaginator;
use Illuminate\Http\Client\ConnectionException;
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

class InstagramFacebookController extends SocialController
{
    protected string $driver = 'facebook';

    protected SocialPlatform $platform = SocialPlatform::InstagramFacebook;

    protected array $scopes = [
        'public_profile',
        'pages_show_list',
        'pages_read_engagement',
        'business_management',
        'instagram_basic',
        'instagram_content_publish',
        'instagram_manage_insights',
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
            ->usingGraphVersion($this->graphVersion())
            ->setScopes($this->scopes)
            ->redirectUrl(route('app.social.instagram-facebook.callback'))
            ->redirect()
            ->getTargetUrl();

        return Inertia::location($url);
    }

    public function callback(Request $request): InertiaResponse|RedirectResponse
    {
        $workspaceId = session('social_connect_workspace');

        if (! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        $reconnectId = session('social_reconnect_id');
        $existingAccount = $reconnectId ? $workspace->socialAccounts()->find($reconnectId) : null;

        try {
            $socialUser = Socialite::driver($this->driver)
                ->usingGraphVersion($this->graphVersion())
                ->redirectUrl(route('app.social.instagram-facebook.callback'))
                ->user();

            // Trigger public_profile API call for Meta app review verification
            Http::get(config('trypost.platforms.instagram-facebook.graph_api').'/me', [
                'fields' => 'id,name',
                'access_token' => $socialUser->token,
            ]);

            $pages = $this->fetchPagesWithInstagram($socialUser->token);

            if (empty($pages)) {
                return $this->popupCallback(false, __('accounts.popup_callback.no_facebook_instagram_pages'), $this->platform->value);
            }

            if (count($pages) === 1) {
                return $this->connectInstagramAccount($workspace, $pages[0], $existingAccount);
            }

            // Multiple pages — show selection
            session([
                'instagram_facebook_oauth' => [
                    'user_token' => $socialUser->token,
                    'pages' => $pages,
                    'reconnect_id' => $reconnectId,
                ],
            ]);

            return redirect()->route('app.social.instagram-facebook.select-page');
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Instagram via Facebook OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    public function selectPage(Request $request): InertiaResponse
    {
        $oauthData = session('instagram_facebook_oauth');
        $workspaceId = session('social_connect_workspace');

        if (! $oauthData || ! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        $pages = collect(data_get($oauthData, 'pages'))
            ->map(fn ($page) => Arr::except($page, ['page_access_token']))
            ->toArray();

        return Inertia::render('accounts/InstagramFacebookPageSelect', [
            'workspace' => $workspace,
            'pages' => $pages,
        ]);
    }

    public function select(Request $request): InertiaResponse
    {
        $request->validate([
            'page_id' => 'required|string',
        ]);

        $oauthData = session('instagram_facebook_oauth');
        $workspaceId = session('social_connect_workspace');

        if (! $oauthData || ! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        $reconnectId = data_get($oauthData, 'reconnect_id');
        $existingAccount = $reconnectId ? $workspace->socialAccounts()->find($reconnectId) : null;

        try {
            $selectedPage = collect(data_get($oauthData, 'pages'))->firstWhere('page_id', $request->page_id);

            if (! $selectedPage) {
                return $this->popupCallback(false, __('accounts.popup_callback.page_not_found'), $this->platform->value);
            }

            $result = $this->connectInstagramAccount($workspace, $selectedPage, $existingAccount);

            session()->forget(['instagram_facebook_oauth', 'social_reconnect_id']);

            return $result;
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Instagram via Facebook page selection error', ['error' => $e->getMessage()]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    private function connectInstagramAccount(Workspace $workspace, array $pageData, $existingAccount): InertiaResponse
    {
        $avatarPath = data_get($pageData, 'ig_picture') ? uploadFromUrl(data_get($pageData, 'ig_picture')) : null;

        $accountData = [
            'platform_user_id' => data_get($pageData, 'ig_id'),
            'username' => data_get($pageData, 'ig_username'),
            'display_name' => data_get($pageData, 'ig_name', data_get($pageData, 'ig_username')),
            'avatar_url' => $avatarPath,
            'access_token' => data_get($pageData, 'page_access_token'),
            'refresh_token' => null,
            'token_expires_at' => null,
            'scopes' => $this->scopes,
            'meta' => [
                'page_id' => data_get($pageData, 'page_id'),
                'page_name' => data_get($pageData, 'page_name'),
            ],
        ];

        if ($existingAccount) {
            $existingAccount->update($accountData);
            $existingAccount->markAsConnected();

            session()->forget('social_reconnect_id');

            return $this->popupCallback(true, __('accounts.popup_callback.reconnected'), $this->platform->value);
        }

        $account = $workspace->socialAccounts()->updateOrCreate(
            [
                'platform' => $this->platform->value,
                'platform_user_id' => data_get($pageData, 'ig_id'),
            ],
            array_merge($accountData, [
                'status' => Status::Connected,
                'error_message' => null,
                'disconnected_at' => null,
            ]),
        );

        return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
    }

    private function fetchPagesWithInstagram(string $userToken): array
    {
        $graphApi = (string) config('trypost.platforms.instagram-facebook.graph_api');

        $pages = GraphPaginator::all("{$graphApi}/me/accounts", [
            'access_token' => $userToken,
            'fields' => 'id,name,username,picture{url},access_token,instagram_business_account',
            'limit' => 100,
        ]);

        return collect($pages)
            ->filter(fn (array $page) => filled(data_get($page, 'instagram_business_account.id')))
            ->map(function (array $page) use ($graphApi) {
                $igId = data_get($page, 'instagram_business_account.id');
                $token = data_get($page, 'access_token');
                $igData = [];

                try {
                    $ig = Http::timeout(15)->connectTimeout(5)->get("{$graphApi}/{$igId}", [
                        'access_token' => $token,
                        'fields' => 'username,name,profile_picture_url',
                    ]);

                    $igData = $ig->successful() ? $ig->json() : [];
                } catch (ConnectionException) {
                    // Page listing still succeeds; username/avatar may be empty.
                }

                return [
                    'page_id' => data_get($page, 'id'),
                    'page_name' => data_get($page, 'name'),
                    'page_picture' => data_get($page, 'picture.data.url'),
                    'page_access_token' => $token,
                    'ig_id' => $igId,
                    'ig_username' => data_get($igData, 'username'),
                    'ig_name' => data_get($igData, 'name'),
                    'ig_picture' => data_get($igData, 'profile_picture_url'),
                ];
            })
            ->values()
            ->all();
    }

    private function graphVersion(): string
    {
        return Uri::of(config('trypost.platforms.instagram-facebook.graph_api'))->path();
    }
}
