<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\HostResolver;
use App\Support\Social\PendingConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\BrowserTestCase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Http::preventStrayRequests();
        fakePwnedPasswords();
        Queue::fake([BootstrapAccountAnalytics::class]);
    })
    ->in('Feature', 'Unit');

pest()->extend(BrowserTestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => fakePwnedPasswords())
    ->in('Browser');

pest()->in('Feature/Social')
    ->beforeEach(fn () => Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]));

/*
|--------------------------------------------------------------------------
| Test Impact Analysis
|--------------------------------------------------------------------------
|
| Only re-run tests affected by local changes, replaying cached results for
| the rest. Scoped to local runs via "locally()" — automatically skipped on
| CI (or when the "--ci" flag is passed), which always runs the full suite.
|
*/

pest()->tia()->locally();

/*
|--------------------------------------------------------------------------
| Browser Timeout
|--------------------------------------------------------------------------
|
| CI runs the browser suite with four processes per runner, so an action can
| wait longer than the plugin's 5 seconds before its element is ready. This is
| the ceiling of each wait, not a delay.
|
*/

pest()->browser()->timeout(15000);

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Issue a real Passport personal access token bound to a workspace and return
 * the plain JWT string. Use the returned token in `Authorization: Bearer ...`
 * to exercise the auth:api + workspace.token middleware stack.
 */
function strongPassword(): string
{
    return 'Tr7#pass-Word-2026';
}

function compromisedPassword(): string
{
    return 'Compromised#Pass-2026';
}

function fakePwnedPasswords(): void
{
    $hash = strtoupper(sha1(compromisedPassword()));

    Http::fake([
        'api.pwnedpasswords.com/range/*' => fn (HttpClientRequest $request) => Http::response(
            str_ends_with($request->url(), substr($hash, 0, 5)) ? substr($hash, 5).':42' : '',
        ),
    ]);
}

function passportToken(User $user, Workspace $workspace, array $scopes = []): string
{
    $result = $user->createToken('Test', $scopes);

    AccessToken::find($result->token->id)
        ->forceFill(['workspace_id' => $workspace->id])
        ->saveQuietly();

    return $result->accessToken;
}

/**
 * Create a workspace + owner + Passport token suitable for hitting the public
 * API. Drop-in replacement for the legacy `createXApiToken` helpers.
 *
 * @param  array{workspace?: Workspace}  $overrides
 * @return array{plain_token: string, workspace: Workspace, user: User}
 */
function createApiTestToken(array $overrides = []): array
{
    $workspace = data_get($overrides, 'workspace');

    if (! $workspace) {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'account_id' => $user->account_id,
            'user_id' => $user->id,
        ]);
        $workspace->members()->attach($user->id, membershipPivot('admin'));
        $user->update(['current_workspace_id' => $workspace->id]);
    } else {
        $user = $workspace->owner ?? User::factory()->create([
            'account_id' => $workspace->account_id,
        ]);

        if ($workspace->account && $workspace->account->owner_id !== $user->id) {
            $workspace->account->update(['owner_id' => $user->id]);
        }
    }

    return [
        'plain_token' => passportToken($user, $workspace),
        'workspace' => $workspace,
        'user' => $user,
    ];
}

/**
 * Pivot columns for a workspace membership: an admin, a member who publishes
 * directly, or a member whose posts need approval.
 *
 * @return array{is_admin: bool, requires_approval: bool}
 */
function membershipPivot(string $access): array
{
    return match ($access) {
        'admin' => ['is_admin' => true, 'requires_approval' => false],
        'member' => ['is_admin' => false, 'requires_approval' => false],
        'approval' => ['is_admin' => false, 'requires_approval' => true],
    };
}

/**
 * A user on the workspace's account, attached with the given access and
 * switched to that workspace.
 *
 * @param  array<string, mixed>  $attributes
 */
function workspaceMember(Workspace $workspace, string $access = 'member', array $attributes = []): User
{
    $user = User::factory()->create(['account_id' => $workspace->account_id, ...$attributes]);
    $workspace->members()->attach($user->id, membershipPivot($access));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

/**
 * A user on the workspace's account whose current workspace is this one but
 * who is not a member of it.
 */
function workspaceOutsider(Workspace $workspace): User
{
    $user = User::factory()->create(['account_id' => $workspace->account_id]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

/**
 * Create an account on the Workspace plan with an active subscription on the
 * given Stripe price, plus N workspaces. Used by the billing-cycle tests.
 *
 * @param  array<string, mixed>  $subscriptionAttributes
 */
function billingAccount(string $price, array $subscriptionAttributes = [], int $workspaces = 1): Account
{
    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update([
        'stripe_monthly_price_id' => 'price_month',
        'stripe_yearly_price_id' => 'price_year',
    ]);

    $account = Account::factory()->create([
        'plan_id' => $plan->id,
        'trial_ends_at' => null,
    ]);

    $account->subscriptions()->create(array_merge([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => $price,
        'quantity' => $workspaces,
    ], $subscriptionAttributes));

    Workspace::factory()->count($workspaces)->create(['account_id' => $account->id]);

    return $account->refresh();
}

/**
 * The session a connect start route leaves behind: the network, the workspace,
 * the card being reconnected and where to return.
 */
function startSocialConnect(Workspace|string $workspace, Platform $platform, SocialAccount|string|null $reconnect = null, ?string $returnTo = null): PendingConnection
{
    $workspace = $workspace instanceof Workspace ? $workspace : (new Workspace)->forceFill(['id' => $workspace]);

    return PendingConnection::start($platform, $workspace, $reconnect instanceof SocialAccount ? $reconnect->id : $reconnect, $returnTo);
}

/**
 * "Finish connection" on the confirmation page, with every offered identity not
 * already connected unless a selection is given.
 *
 * @param  array<int, string>|null  $identities
 */
function finishSocialConnect(Platform $platform, ?array $identities = null): TestResponse
{
    return test()->post(route('app.social.connect.finish', $platform), [
        'identities' => $identities ?? array_values(array_diff(
            PendingConnection::current()?->identityKeys() ?? [],
            PendingConnection::current()?->lockedIdentityKeys() ?? [],
        )),
    ]);
}

/**
 * Finish always lands on the publish page of the channel it connected: the given
 * one, else any channel of the test workspace.
 */
function assertFinishedOnChannel(TestResponse $response, ?SocialAccount $channel = null): TestResponse
{
    if ($channel !== null) {
        return $response->assertRedirect(route('app.channels.publish', $channel));
    }

    $location = (string) $response->headers->get('Location');

    preg_match('#/channels/([^/]+)/publish$#', $location, $matches);

    expect(SocialAccount::query()->whereKey($matches[1] ?? '')->exists())->toBeTrue("Finish redirected to {$location}");

    return $response;
}

/**
 * Why the pending connection stopped, as the confirmation page reads it.
 */
function socialConnectFailure(): ?string
{
    return PendingConnection::current()?->failure();
}

/**
 * Every host name resolves to one public documentation address, so a fetch
 * through SafeHttpFetcher never depends on real DNS. IP literals are still
 * vetted by the guard itself.
 */
function fakePublicDns(string $address = '93.184.216.34'): void
{
    test()->mock(HostResolver::class)->shouldReceive('addresses')->andReturn([$address]);
}

/**
 * Attach an active default subscription to the given account.
 */
function subscribeAccount(Account $account): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
}

/**
 * Insert an OAuth client suitable for MCP connection tests.
 */
function mcpOauthClient(string $name = 'My Agent'): string
{
    $id = (string) Str::uuid();

    DB::table('oauth_clients')->insert([
        'id' => $id,
        'name' => $name,
        'secret' => null,
        'provider' => null,
        'redirect_uris' => '[]',
        'grant_types' => json_encode(['authorization_code', 'refresh_token']),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * @return array<string, string>
 */
function oauthAuthorizeQuery(
    string $clientId,
    string $redirectUri = 'https://client.example/callback',
    string $prompt = 'consent',
): array {
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [
        'client_id' => $clientId,
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'test-state',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'prompt' => $prompt,
    ];
}

/**
 * Create an active OAuth access token for MCP connection tests.
 *
 * @param  list<string>  $scopes
 */
function mcpAccessToken(
    User $user,
    string $clientId,
    ?Workspace $workspace = null,
    array $scopes = ['mcp:use'],
): AccessToken {
    $token = new AccessToken;
    $token->forceFill([
        'id' => Str::random(80),
        'user_id' => $user->id,
        'client_id' => $clientId,
        'workspace_id' => $workspace?->id,
        'name' => 'MCP',
        'scopes' => $scopes,
        'revoked' => false,
        'expires_at' => now()->addYear(),
    ])->save();

    return $token->refresh();
}

/**
 * Issue a Passport token, attach it to a dedicated MCP OAuth client, and bind
 * it to a workspace — the post-#222 shape used by middleware / MCP endpoint tests.
 *
 * @param  list<string>  $scopes
 * @return array{token: AccessToken, plain_token: string}
 */
function mcpBearerToken(User $user, Workspace $workspace, array $scopes = ['mcp:use']): array
{
    $result = $user->createToken('MCP', $scopes);
    $token = AccessToken::query()->findOrFail($result->token->id);

    // Reassign to a dedicated MCP client so we never mutate Passport's shared
    // personal-access client (which would poison PAT fixtures in the same run).
    $token->forceFill([
        'client_id' => mcpOauthClient(),
        'workspace_id' => $workspace->id,
    ])->saveQuietly();

    return [
        'token' => $token->refresh(),
        'plain_token' => $result->accessToken,
    ];
}

/**
 * Move a member onto a shared account (stranded-member / invitee fixture).
 *
 * @return array{
 *     owner: User,
 *     member: User,
 *     shared_workspaces: list<Workspace>
 * }
 */
function strandedMemberOnSharedAccount(
    int $sharedWorkspaces = 0,
    bool $attachMember = true,
    bool $attachMemberToAll = true,
    bool $setMemberCurrent = false,
    ?User $owner = null,
    ?string $memberEmail = null,
): array {
    $owner ??= User::factory()->create();
    $member = User::factory()->create(array_filter([
        'email' => $memberEmail,
    ]));

    // Closed-account model: the member's empty signup shell is gone after
    // accepting the invite, so drop it here to match the real state.
    $member->account?->delete();

    $shared = [];

    for ($i = 0; $i < $sharedWorkspaces; $i++) {
        $workspace = Workspace::factory()->create([
            'account_id' => $owner->account_id,
            'user_id' => $owner->id,
        ]);

        $workspace->members()->syncWithoutDetaching([
            $owner->id => membershipPivot('admin'),
        ]);

        if ($attachMember && ($attachMemberToAll || $i === 0)) {
            $workspace->members()->attach($member->id, membershipPivot('member'));
        }

        $shared[] = $workspace;
    }

    $member->update([
        'account_id' => $owner->account_id,
        'current_workspace_id' => ($setMemberCurrent && $shared !== [])
            ? $shared[0]->id
            : null,
    ]);

    return [
        'owner' => $owner->fresh(),
        'member' => $member->fresh(),
        'shared_workspaces' => $shared,
    ];
}

/**
 * A workspace reachable both through a REST API token and as the MCP actor,
 * for tests that run one scenario through both surfaces.
 *
 * @return array{user: User, workspace: Workspace, token: string}
 */
function parityContext(): array
{
    $result = createApiTestToken();

    return [
        'user' => $result['user']->fresh(),
        'workspace' => $result['workspace'],
        'token' => $result['plain_token'],
    ];
}

/**
 * @return array<string, string>
 */
function parityApi(string $token): array
{
    return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
}

/**
 * The structured content an MCP list tool returns for the same page as a paginated API list response.
 *
 * @return array<string, mixed>
 */
function parityMcpPage(string $key, TestResponse $api): array
{
    return [
        $key => $api->json('data'),
        'total' => $api->json('meta.total'),
        'per_page' => $api->json('meta.per_page'),
        'current_page' => $api->json('meta.current_page'),
        'last_page' => $api->json('meta.last_page'),
    ];
}

/**
 * Wait until the Inter web font is loaded, so layout assertions measure the
 * font users see instead of whichever fallback the operating system has.
 */
function waitForWebFonts(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            const interFaces = () => [...document.fonts].filter((face) => face.family.replaceAll('"', '') === 'Inter');
            for (let attempt = 0; attempt < 100 && interFaces().length === 0; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            await Promise.all(interFaces().map((face) => face.load().catch(() => null)));
            await document.fonts.ready;
        })();
    JS);
}
