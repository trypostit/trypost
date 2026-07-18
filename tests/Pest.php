<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
