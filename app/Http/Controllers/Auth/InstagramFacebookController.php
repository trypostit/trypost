<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\ConnectPopupException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\Meta\ManagedPages;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class InstagramFacebookController extends MetaController
{
    protected string $pageFields = 'id,name,username,picture{url},access_token,instagram_business_account';

    protected string $noPagesKey = 'accounts.popup_callback.no_facebook_instagram_pages';

    protected SocialPlatform $platform = SocialPlatform::InstagramFacebook;

    /**
     * Instagram accounts described per pool round. Each Page carries its own
     * access token, so the lookups cannot be batched into one `ids=` call —
     * they run concurrently instead, in rounds, so a portfolio holding
     * hundreds of Pages does not serialise the OAuth callback.
     */
    private const INSTAGRAM_LOOKUPS_PER_ROUND = 20;

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

        $this->rememberConnectSession($request, $workspace);

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
        $workspace = $this->connectWorkspace($request);

        $existingAccount = $this->reconnectAccount($workspace);

        try {
            $socialUser = Socialite::driver($this->driver)
                ->usingGraphVersion($this->graphVersion())
                ->redirectUrl(route('app.social.instagram-facebook.callback'))
                ->user();

            $this->touchProfile($socialUser->token);

            $granted = $this->grantedScopes($socialUser->token);

            if ($granted instanceof InertiaResponse) {
                return $granted;
            }

            $walk = ManagedPages::forUser($this->graphApi(), $socialUser->token, $this->pageFields, $granted, $this->deadline());

            $listed = collect($walk->pages)
                ->filter(fn (array $page) => filled(data_get($page, 'instagram_business_account.id')))
                ->values()
                ->all();

            $publishable = ManagedPages::publishable($listed);

            if (empty($publishable)) {
                return $this->noPagesOnOffer($walk, $listed);
            }

            $connectable = $this->filterConnectableIdentities(
                $workspace,
                $publishable,
                'instagram_business_account.id',
                $existingAccount,
            );

            if (empty($connectable)) {
                return $this->noConnectableIdentities($existingAccount, 'page_not_found', $walk->complete);
            }

            $pages = $this->describeInstagramAccounts($connectable);

            if (count($pages) === 1 && ($walk->complete || $existingAccount !== null)) {
                return $this->connectInstagramAccount($workspace, $pages[0], $existingAccount, $granted);
            }

            // Multiple pages — show selection
            session([
                'instagram_facebook_oauth' => [
                    'user_token' => $socialUser->token,
                    'scopes' => $granted,
                    'pages' => $pages,
                    'reconnect_id' => $existingAccount?->id,
                ],
            ]);

            return redirect()->route('app.social.instagram-facebook.select-page');
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
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

        if (! $oauthData) {
            throw new ConnectPopupException('session_expired', $this->platform);
        }

        $workspace = $this->connectWorkspace($request);

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

        if (! $oauthData) {
            throw new ConnectPopupException('session_expired', $this->platform);
        }

        $workspace = $this->connectWorkspace($request);

        $existingAccount = $this->reconnectAccount($workspace, data_get($oauthData, 'reconnect_id'));

        try {
            $selectedPage = collect(data_get($oauthData, 'pages'))->firstWhere('page_id', $request->page_id);

            if (! $selectedPage) {
                return $this->popupCallback(false, __('accounts.popup_callback.page_not_found'), $this->platform->value);
            }

            $result = $this->connectInstagramAccount(
                $workspace,
                $selectedPage,
                $existingAccount,
                data_get($oauthData, 'scopes', $this->scopes),
            );

            session()->forget('instagram_facebook_oauth');

            return $result;
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Instagram via Facebook page selection error', ['error' => $e->getMessage()]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    /**
     * @param  array<string, mixed>  $pageData
     * @param  array<int, string>  $scopes
     */
    private function connectInstagramAccount(Workspace $workspace, array $pageData, ?SocialAccount $existingAccount, array $scopes): InertiaResponse
    {
        $avatarPath = data_get($pageData, 'ig_picture') ? uploadFromUrl(data_get($pageData, 'ig_picture')) : null;

        // A lookup we never made says nothing about the handle a reconnect already has.
        $described = (bool) data_get($pageData, 'ig_described');

        SocialAccount::connectIdentity(
            $workspace,
            $this->platform,
            (string) data_get($pageData, 'ig_id'),
            array_diff_key([
                'username' => data_get($pageData, 'ig_username'),
                'display_name' => data_get($pageData, 'ig_name')
                    ?? data_get($pageData, 'ig_username')
                    ?? data_get($pageData, 'page_name'),
                'avatar_url' => $avatarPath,
                'access_token' => data_get($pageData, 'page_access_token'),
                'refresh_token' => null,
                'token_expires_at' => null,
                'scopes' => $scopes,
                'status' => Status::Connected,
                'error_message' => null,
                'disconnected_at' => null,
                'meta' => [
                    'page_id' => data_get($pageData, 'page_id'),
                    'page_name' => data_get($pageData, 'page_name'),
                ],
            ], $described ? [] : ['username' => true, 'avatar_url' => true]),
            $existingAccount,
        );

        return $this->connectedCallback($existingAccount);
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @return list<array<string, mixed>>
     */
    private function describeInstagramAccounts(array $pages): array
    {
        return collect($pages)
            ->chunk(self::INSTAGRAM_LOOKUPS_PER_ROUND)
            ->flatMap(fn (Collection $round) => $this->describeRound($round, $this->deadline()))
            ->values()
            ->all();
    }

    /**
     * Past the deadline the lookups are skipped rather than dropped: the Page still
     * connects, falling back to its own name, with no Instagram handle or avatar.
     *
     * @param  Collection<int, array<string, mixed>>  $pages
     * @return Collection<int, array<string, mixed>>
     */
    private function describeRound(Collection $pages, float $deadline): Collection
    {
        $pages = $pages->values();
        $graphApi = $this->graphApi();

        $described = microtime(true) < $deadline;

        $responses = $described ? Http::pool(fn (Pool $pool) => $pages
            ->map(fn (array $page) => $pool
                ->timeout(15)
                ->connectTimeout(5)
                ->get("{$graphApi}/".data_get($page, 'instagram_business_account.id'), [
                    'access_token' => data_get($page, 'access_token'),
                    'fields' => 'username,name,profile_picture_url',
                ]))
            ->all()) : [];

        return $pages->map(function (array $page, int $index) use ($responses, $described) {
            $response = data_get($responses, $index);
            $igData = $response instanceof ClientResponse && $response->successful() ? $response->json() : [];

            return [
                'page_id' => data_get($page, 'id'),
                'page_name' => data_get($page, 'name'),
                'page_picture' => data_get($page, 'picture.data.url'),
                'page_access_token' => data_get($page, 'access_token'),
                'ig_id' => data_get($page, 'instagram_business_account.id'),
                'ig_username' => data_get($igData, 'username'),
                'ig_name' => data_get($igData, 'name'),
                'ig_picture' => data_get($igData, 'profile_picture_url'),
                'ig_described' => $described && $response instanceof ClientResponse,
            ];
        });
    }

    private function graphVersion(): string
    {
        return Uri::of($this->graphApi())->path();
    }
}
