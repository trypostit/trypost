<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\PostHog\WorkspaceEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'services.posthog.enabled' => true,
        'services.posthog.api_key' => 'phc_test_key',
    ]);
    Queue::fake([CaptureAccountEvent::class]);
});

$ownerOn = function (Slug $slug): User {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::where('slug', $slug)->value('id')]);
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user->fresh();
};

$clickNewWorkspaceInSidebar = function (mixed $page): void {
    waitFor($page, '@sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
    waitFor($page, '@sidebar-workspaces-trigger');
    $page->click('@sidebar-workspaces-trigger');
    waitFor($page, '@sidebar-create-workspace');
    $page->hover('@sidebar-create-workspace');
    $page->script('window.__hoveredAt = Date.now()');
    waitForCondition($page, 'Date.now() - window.__hoveredAt > 500');

    expect(Queue::pushed(CaptureAccountEvent::class))->toHaveCount(0);

    $page->click('@sidebar-create-workspace');
    waitForPath($page, parse_url(route('app.workspaces.create'), PHP_URL_PATH));
};

test('at the cap, new workspace in the sidebar opens the create page with the upgrade dialog and tracks limit_hit', function () use ($ownerOn, $clickNewWorkspaceInSidebar) {
    $user = $ownerOn(Slug::Socials);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $clickNewWorkspaceInSidebar($page);

    waitFor($page, '@workspace-upgrade-dialog');
    $page->assertVisible('@workspace-upgrade-dialog');

    Queue::assertPushedTimes(CaptureAccountEvent::class, 1);
    Queue::assertPushed(CaptureAccountEvent::class, fn (CaptureAccountEvent $job) => $job->event === WorkspaceEvent::LimitHit);

    $page->click('@dialog-close');
    waitForGone($page, '@workspace-upgrade-dialog');

    $page->fill('@workspaces-create-name', 'Second')
        ->click('@workspaces-create-submit');

    waitFor($page, '@workspace-upgrade-dialog');
    $page->assertVisible('@workspace-upgrade-dialog')
        ->assertPathIs(parse_url(route('app.workspaces.create'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();

    expect($user->account->workspaces()->count())->toBe(1)
        ->and(Workspace::where('name', 'Second')->exists())->toBeFalse();
});

test('below the cap, new workspace in the sidebar opens the create page without the upgrade dialog', function () use ($ownerOn, $clickNewWorkspaceInSidebar) {
    $user = $ownerOn(Slug::Workspaces);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $clickNewWorkspaceInSidebar($page);

    waitFor($page, '@workspaces-create-name');
    $page->assertMissing('@workspace-upgrade-dialog')
        ->assertNoJavaScriptErrors();

    Queue::assertNotPushed(CaptureAccountEvent::class);
});
