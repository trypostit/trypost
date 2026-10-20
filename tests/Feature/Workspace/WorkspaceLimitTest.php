<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\PostHog\WorkspaceEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
});

/**
 * User::factory() does not create a workspace — only CreateUser does.
 * Insert one so Socials / legacy start already at their cap of 1.
 */
$onPlan = function (Slug $slug): User {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::where('slug', $slug)->value('id')]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    // The controller guard checks hasActiveSubscription() *before* the cap.
    // Without this, GET at the Socials cap still renders, but store flashes
    // subscription_required instead of limit_reached.
    subscribeAccount($user->account);

    return $user->fresh();
};

test('an account with no plan can create its first workspace', function () {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => null]);

    expect($user->account->workspaces()->count())->toBe(0)
        ->and($user->fresh()->account->workspaceLimit())->toBe(1)
        ->and($user->fresh()->account->canCreateWorkspace())->toBeTrue();
});

test('an account with no plan cannot create a second workspace', function () {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => null]);

    Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    expect($user->fresh()->account->workspaceLimit())->toBe(1)
        ->and($user->fresh()->account->canCreateWorkspace())->toBeFalse();
});

test('a subscribed account without a plan is still capped at one workspace', function () {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => null]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    subscribeAccount($user->account);

    $this->actingAs($user->fresh())
        ->get(route('app.workspaces.create'))
        ->assertRedirect(route('app.workspaces.index'))
        ->assertSessionHas('flash.error', __('workspaces.limit_reached'));
});

test('the socials plan blocks a second workspace', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);

    expect($user->account->workspaces()->count())->toBe(1)
        ->and($user->account->canCreateWorkspace())->toBeFalse();
});

test('the workspaces plan allows more workspaces', function () use ($onPlan) {
    $user = $onPlan(Slug::Workspaces);

    Workspace::factory()->count(3)->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    expect($user->account->canCreateWorkspace())->toBeTrue();
});

test('the legacy plan is capped at one workspace', function () use ($onPlan) {
    $user = $onPlan(Slug::Workspace);

    expect($user->account->canCreateWorkspace())->toBeFalse();
});

test('self-hosted ignores the cap', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);

    config(['trypost.self_hosted' => true]);

    expect($user->account->canCreateWorkspace())->toBeTrue();
});

test('the create page sends the user to the workspaces list when the plan is at its cap', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->from(route('app.posts.index'))
        ->get(route('app.workspaces.create'))
        ->assertRedirect(route('app.workspaces.index'))
        ->assertSessionHas('flash.error', __('workspaces.limit_reached'));
});

test('the create page renders below the cap', function () use ($onPlan) {
    $user = $onPlan(Slug::Workspaces);

    $this->actingAs($user)
        ->get(route('app.workspaces.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('workspaces/Create', false)
            ->missing('upgradeRequired')
        );
});

test('a subscribed account without a plan cannot store a second workspace', function () {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => null]);

    Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    subscribeAccount($user->account);

    $this->actingAs($user->fresh())
        ->from(route('app.posts.index'))
        ->post(route('app.workspaces.store'), ['name' => 'Second'])
        ->assertRedirect(route('app.workspaces.index'))
        ->assertSessionHas('flash.error', __('workspaces.limit_reached'));

    expect($user->account->workspaces()->count())->toBe(1);
});

test('a direct store POST cannot exceed the cap', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->from(route('app.workspaces.create'))
        ->post(route('app.workspaces.store'), ['name' => 'Second'])
        ->assertRedirect(route('app.workspaces.index'))
        ->assertSessionHas('flash.error', __('workspaces.limit_reached'));

    expect($user->account->workspaces()->count())->toBe(1);
});

test('the workspaces plan can store another workspace', function () use ($onPlan) {
    $user = $onPlan(Slug::Workspaces);

    $this->actingAs($user)
        ->post(route('app.workspaces.store'), ['name' => 'Second'])
        ->assertRedirect(route('app.posts.index'));

    expect($user->account->workspaces()->count())->toBe(2);
});

test('storing a workspace checks the cap under a lock on the account row', function () use ($onPlan) {
    $user = $onPlan(Slug::Workspaces);
    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = Str::lower($query->sql);
    });

    $this->actingAs($user)
        ->post(route('app.workspaces.store'), ['name' => 'Second'])
        ->assertRedirect(route('app.posts.index'));

    $lock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'accounts') && str_contains($sql, 'for update'));
    $insert = collect($statements)->search(fn (string $sql): bool => str_starts_with($sql, 'insert into') && str_contains($sql, 'workspaces'));

    expect($lock)->toBeInt()
        ->and($insert)->toBeInt()
        ->and($lock)->toBeLessThan($insert);
});

$enablePostHog = function (): void {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    Queue::fake([CaptureAccountEvent::class]);
};

$limitHitPushed = fn (User $user, int $workspacesCount = 1) => fn (CaptureAccountEvent $job): bool => $job->event === WorkspaceEvent::LimitHit
    && $job->distinctId === (string) $user->id
    && $job->accountId === (string) $user->account_id
    && $job->properties === [
        'plan_slug' => Slug::Socials->value,
        'workspaces_count' => $workspacesCount,
        'workspace_limit' => 1,
    ];

test('the creation check at the limit refuses and tracks limit_hit', function () use ($onPlan, $enablePostHog, $limitHitPushed) {
    $enablePostHog();
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->postJson(route('app.workspaces.creation-check.store'))
        ->assertOk()
        ->assertExactJson(['allowed' => false]);

    Queue::assertPushedOn('posthog', CaptureAccountEvent::class, $limitHitPushed($user));
    Queue::assertPushedTimes(CaptureAccountEvent::class, 1);
});

test('the creation check below the limit allows and does not track', function () use ($onPlan, $enablePostHog) {
    $enablePostHog();
    $user = $onPlan(Slug::Workspaces);

    $this->actingAs($user)
        ->postJson(route('app.workspaces.creation-check.store'))
        ->assertOk()
        ->assertExactJson(['allowed' => true]);

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

test('the creation check allows self-hosted at any count and does not track', function () use ($onPlan, $enablePostHog) {
    $enablePostHog();
    $user = $onPlan(Slug::Socials);
    config(['trypost.self_hosted' => true]);

    $this->actingAs($user)
        ->postJson(route('app.workspaces.creation-check.store'))
        ->assertExactJson(['allowed' => true]);

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

test('the creation check allows the first workspace and does not track', function () use ($enablePostHog) {
    $enablePostHog();
    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::where('slug', Slug::Socials)->value('id')]);
    subscribeAccount($user->account);

    $this->actingAs($user->fresh())
        ->postJson(route('app.workspaces.creation-check.store'))
        ->assertExactJson(['allowed' => true]);

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

test('the creation check refuses an account without a plan at one workspace, as the props did on main', function () use ($enablePostHog) {
    $enablePostHog();
    $user = User::factory()->create();
    $user->account->update(['plan_id' => null]);
    Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $this->actingAs($user->fresh())
        ->postJson(route('app.workspaces.creation-check.store'))
        ->assertExactJson(['allowed' => false]);
});

test('the creation check counts the account workspaces, not the ones the owner created, as the props did on main', function () use ($enablePostHog, $limitHitPushed) {
    $enablePostHog();
    $owner = User::factory()->create();
    $owner->account->update(['plan_id' => Plan::where('slug', Slug::Socials)->value('id')]);
    subscribeAccount($owner->account);
    $creator = User::factory()->create(['account_id' => $owner->account_id]);
    Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $creator->id]);

    expect($owner->fresh()->ownedWorkspacesCount())->toBe(0);

    $this->actingAs($owner->fresh())
        ->postJson(route('app.workspaces.creation-check.store'))
        ->assertOk()
        ->assertExactJson(['allowed' => false]);

    Queue::assertPushedOn('posthog', CaptureAccountEvent::class, $limitHitPushed($owner));
    Queue::assertPushedTimes(CaptureAccountEvent::class, 1);
});

test('the creation check is for the account owner only', function () use ($onPlan) {
    $owner = $onPlan(Slug::Socials);
    $member = User::factory()->create(['account_id' => $owner->account_id]);
    $workspace = $owner->account->workspaces()->first();
    $workspace->members()->attach($member->id, membershipPivot('admin'));
    $member->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($member->fresh())
        ->postJson(route('app.workspaces.creation-check.store'))
        ->assertForbidden();
});

test('a failed limit_hit dispatch still refuses the creation check', function () use ($onPlan) {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    failDispatchOf(CaptureAccountEvent::class);
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->postJson(route('app.workspaces.creation-check.store'))
        ->assertOk()
        ->assertExactJson(['allowed' => false]);
});

test('opening the create page at the limit sends the user to the workspaces list and tracks limit_hit once', function () use ($onPlan, $enablePostHog, $limitHitPushed) {
    $enablePostHog();
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->from(route('app.posts.index'))
        ->get(route('app.workspaces.create'))
        ->assertRedirect(route('app.workspaces.index'))
        ->assertSessionHas('flash.error', __('workspaces.limit_reached'));

    Queue::assertPushedOn('posthog', CaptureAccountEvent::class, $limitHitPushed($user));
    Queue::assertPushedTimes(CaptureAccountEvent::class, 1);
});

test('the create page at the limit still renders for a user without a current workspace, so it never loops with the workspace guard', function () use ($onPlan) {
    $user = $onPlan(Slug::Socials);
    $user->update(['current_workspace_id' => null]);

    $this->actingAs($user->fresh())
        ->get(route('app.workspaces.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspaces/Create', false));
});

test('opening the create page below the limit renders it and does not track', function () use ($onPlan, $enablePostHog) {
    $enablePostHog();
    $user = $onPlan(Slug::Workspaces);

    $this->actingAs($user)
        ->get(route('app.workspaces.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspaces/Create', false));

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

test('a store refused at the limit creates nothing and is tracked once', function () use ($onPlan, $enablePostHog, $limitHitPushed) {
    $enablePostHog();
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->from(route('app.workspaces.create'))
        ->followingRedirects()
        ->post(route('app.workspaces.store'), ['name' => 'Second'])
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspaces/Index', false));

    Queue::assertPushedOn('posthog', CaptureAccountEvent::class, $limitHitPushed($user));
    Queue::assertPushedTimes(CaptureAccountEvent::class, 1);
    expect($user->account->workspaces()->count())->toBe(1)
        ->and(Workspace::where('name', 'Second')->exists())->toBeFalse();
});

test('a store refused for a missing subscription does not track limit_hit', function () use ($enablePostHog) {
    $enablePostHog();
    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::where('slug', Slug::Socials)->value('id')]);
    Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $this->actingAs($user->fresh())
        ->post(route('app.workspaces.store'), ['name' => 'Second'])
        ->assertRedirect(route('app.billing.index'));

    Queue::assertNotPushed(CaptureAccountEvent::class);
});

test('a failed limit_hit dispatch still sends the user away from the create page', function () use ($onPlan) {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
    failDispatchOf(CaptureAccountEvent::class);
    $user = $onPlan(Slug::Socials);

    $this->actingAs($user)
        ->get(route('app.workspaces.create'))
        ->assertRedirect(route('app.workspaces.index'))
        ->assertSessionHas('flash.error', __('workspaces.limit_reached'));
});
