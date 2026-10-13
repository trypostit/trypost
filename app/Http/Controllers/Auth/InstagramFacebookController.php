<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Services\Social\Meta\ManagedPages;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Uri;
use Inertia\Inertia;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class InstagramFacebookController extends MetaController
{
    protected string $pageFields = 'id,name,username,picture{url},access_token,instagram_business_account';

    protected string $noPagesKey = 'no_facebook_instagram_pages';

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
        // first comment after publish (FirstCommentPoster)
        'instagram_manage_comments',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        $driver = Socialite::driver($this->driver)
            ->usingGraphVersion($this->graphVersion())
            ->setScopes($this->scopes)
            ->redirectUrl(route('app.social.instagram-facebook.callback'));

        $driver = $this->switchingAccount($request)
            ? $driver->with(['auth_type' => self::SWITCH_ACCOUNT_AUTH_TYPE])
            : $driver->reRequest();

        return Inertia::location($driver->redirect()->getTargetUrl());
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);
        $reconnect = $this->reconnectAccount($workspace);

        try {
            $socialUser = Socialite::driver($this->driver)
                ->usingGraphVersion($this->graphVersion())
                ->redirectUrl(route('app.social.instagram-facebook.callback'))
                ->user();

            $this->touchProfile($socialUser->token);

            $granted = $this->grantedScopes($socialUser->token);

            if ($granted instanceof RedirectResponse) {
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

            $connectable = collect($publishable)
                ->filter(fn (array $page): bool => $this->connectableIdentities($workspace, [[
                    'platform' => $this->platform->value,
                    'platform_user_id' => (string) data_get($page, 'instagram_business_account.id'),
                ]], $reconnect) !== [])
                ->values()
                ->all();

            $identities = array_map(
                fn (array $page): array => $this->toIdentity($page, $granted),
                $this->describeInstagramAccounts($connectable),
            );

            return $this->offerIdentities($workspace, $identities, $reconnect, 'page_not_found', $walk->complete);
        } catch (Exception $e) {
            Log::error('Instagram via Facebook OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }

    /**
     * A lookup we never made says nothing about the handle or avatar a reconnect
     * already has, so an undescribed account leaves both as they are.
     *
     * @param  array<string, mixed>  $page
     * @param  array<int, string>  $scopes
     * @return array<string, mixed>
     */
    private function toIdentity(array $page, array $scopes): array
    {
        $described = (bool) data_get($page, 'ig_described');
        $name = data_get($page, 'ig_name') ?? data_get($page, 'ig_username') ?? data_get($page, 'page_name');

        return PendingConnection::identity(
            $this->platform,
            (string) data_get($page, 'ig_id'),
            $name,
            data_get($page, 'ig_username'),
            data_get($page, 'ig_picture'),
            $this->platform->identityType()->value,
            array_diff_key([
                'username' => data_get($page, 'ig_username'),
                'display_name' => $name,
                'access_token' => data_get($page, 'page_access_token'),
                'refresh_token' => null,
                'token_expires_at' => null,
                'scopes' => $scopes,
                'meta' => [
                    'page_id' => data_get($page, 'page_id'),
                    'page_name' => data_get($page, 'page_name'),
                ],
            ], $described ? [] : ['username' => true]),
            keepsAvatar: ! $described,
        );
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
