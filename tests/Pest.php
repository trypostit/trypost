<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
    ->in('Feature', 'Unit');

pest()->extend(BrowserTestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Browser');

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
        $workspace->members()->attach($user->id, [
            'role' => Role::Admin->value,
        ]);
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

function feedFixture(string $name): string
{
    return file_get_contents(base_path("tests/fixtures/feeds/{$name}.xml"));
}

/**
 * Create an account on the Workspace plan with an active subscription on the
 * given Stripe price, plus N workspaces. Used by the billing-cycle tests.
 *
 * @param  array<string, mixed>  $subscriptionAttributes
 */
function billingAccount(string $price, array $subscriptionAttributes = [], int $workspaces = 1): Account
{
    $plan = Plan::query()->firstOrFail();
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
            $owner->id => ['role' => Role::Admin->value],
        ]);

        if ($attachMember && ($attachMemberToAll || $i === 0)) {
            $workspace->members()->attach($member->id, [
                'role' => Role::Member->value,
            ]);
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
