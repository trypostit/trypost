<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Laravel\Cashier\Cashier;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->socials = Plan::where('slug', Slug::Socials)->firstOrFail();
    $this->socials->update([
        'stripe_monthly_price_id' => 'price_socials_monthly',
        'stripe_yearly_price_id' => 'price_socials_yearly',
    ]);

    $this->workspaces = Plan::where('slug', Slug::Workspaces)->firstOrFail();
    $this->workspaces->update([
        'stripe_monthly_price_id' => 'price_workspaces_monthly',
        'stripe_yearly_price_id' => 'price_workspaces_yearly',
    ]);
});

afterEach(function () {
    RecordingSwapSubscription::$swappedPrices = [];
    Cashier::useSubscriptionModel(Subscription::class);
});

$withWorkspace = function (User $user): Workspace {
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $workspace;
};

test('a non-owner cannot change the plan', function () use ($withWorkspace) {
    $owner = User::factory()->create();
    $workspace = $withWorkspace($owner);
    subscribeAccount($owner->account);

    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($member->fresh())
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $this->workspaces->id,
            'interval' => 'monthly',
        ])
        ->assertForbidden();
});

test('an archived plan is rejected', function () use ($withWorkspace) {
    $user = User::factory()->create();
    $withWorkspace($user);
    subscribeAccount($user->account);
    $legacy = Plan::where('slug', Slug::Workspace)->firstOrFail();

    $this->actingAs($user->fresh())
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $legacy->id,
            'interval' => 'monthly',
        ])
        ->assertSessionHasErrors('plan_id');
});

test('downgrading is denied while the account holds more workspaces than the target allows', function () {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->workspaces->id]);

    Workspace::factory()->count(2)->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);

    subscribeAccount($account);

    $response = Gate::forUser($user)->inspect('swapPlan', [$account->fresh(), $this->socials]);

    expect($response->denied())->toBeTrue()
        ->and($response->message())->toBe(__('billing.flash.too_many_workspaces', [
            'count' => 2,
            'limit' => 1,
        ]));
});

test('change-plan flashes when the account holds more workspaces than the target allows', function () use ($withWorkspace) {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->workspaces->id]);

    $withWorkspace($user);
    Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);

    subscribeAccount($account);

    $this->actingAs($user->fresh())
        ->from(route('app.billing.index'))
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $this->socials->id,
            'interval' => 'monthly',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHas('flash.error', __('billing.flash.too_many_workspaces', [
            'count' => 2,
            'limit' => 1,
        ]));

    expect($account->fresh()->plan_id)->toBe($this->workspaces->id);
});

test('downgrading is allowed once the account is inside the target limit', function () {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->workspaces->id]);

    subscribeAccount($account);

    $response = Gate::forUser($user)->inspect('swapPlan', [$account->fresh(), $this->socials]);

    expect($response->allowed())->toBeTrue();
});

test('billing lists socials as denied when the account has too many workspaces', function () use ($withWorkspace) {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->workspaces->id]);

    $withWorkspace($user);
    Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);

    subscribeAccount($account);

    $this->actingAs($user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/account/Billing', false)
            ->where('workspaceCount', 2)
            ->where('plans.0.id', $this->socials->id)
            ->where('plans.0.workspace_limit', 1)
            ->has('plans', 2)
        );
});

test('cloud requests share plans for the workspace upgrade paywall', function () use ($withWorkspace) {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->socials->id]);
    $withWorkspace($user);
    subscribeAccount($account);

    $this->actingAs($user->fresh())
        ->get(route('app.billing.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('plans', 2)
            ->where('workspaceCount', 1)
            ->where('plans.0.slug', 'socials')
            ->where('plans.1.slug', 'workspaces')
        );
});

test('change-plan swaps the price and writes plan_id', function () use ($withWorkspace) {
    Cashier::useSubscriptionModel(RecordingSwapSubscription::class);

    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->socials->id]);
    $withWorkspace($user);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_socials_monthly',
    ]);

    $this->actingAs($user->fresh())
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $this->workspaces->id,
            'interval' => 'monthly',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionHas('flash.success', __('billing.flash.plan_changed', [
            'plan' => $this->workspaces->name,
        ]));

    expect($account->fresh()->plan_id)->toBe($this->workspaces->id)
        ->and(RecordingSwapSubscription::$swappedPrices)->toBe(['price_workspaces_monthly']);
});

test('change-plan is a no-op when the subscription is already on that price', function () use ($withWorkspace) {
    $user = User::factory()->create();
    $account = $user->account;
    $account->update(['plan_id' => $this->socials->id]);
    $withWorkspace($user);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_socials_yearly',
    ]);

    $this->actingAs($user->fresh())
        ->post(route('app.billing.change-plan'), [
            'plan_id' => $this->socials->id,
            'interval' => 'yearly',
        ])
        ->assertRedirect(route('app.billing.index'))
        ->assertSessionMissing('flash.success');
});

class RecordingSwapSubscription extends Subscription
{
    protected $table = 'subscriptions';

    /**
     * @var list<string|array<int, string>>
     */
    public static array $swappedPrices = [];

    public function getForeignKey(): string
    {
        return 'subscription_id';
    }

    public function swap(string|array $prices, array $options = []): static
    {
        self::$swappedPrices[] = $prices;

        return $this;
    }
}
