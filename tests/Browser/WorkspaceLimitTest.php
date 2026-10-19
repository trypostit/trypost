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
    $page->click('@sidebar-create-workspace');
};

test('at the cap, new workspace in the sidebar opens the upgrade dialog in place and tracks limit_hit', function () use ($ownerOn, $clickNewWorkspaceInSidebar) {
    $user = $ownerOn(Slug::Socials);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $clickNewWorkspaceInSidebar($page);

    waitFor($page, '@workspace-upgrade-dialog');
    $page->assertVisible('@workspace-upgrade-dialog')
        ->assertPathIs(parse_url(route('app.posts.index'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();

    Queue::assertPushedTimes(CaptureAccountEvent::class, 1);
    Queue::assertPushed(CaptureAccountEvent::class, fn (CaptureAccountEvent $job) => $job->event === WorkspaceEvent::LimitHit);
    expect($user->account->workspaces()->count())->toBe(1);
});

test('below the cap, new workspace in the sidebar opens the create page and creates the workspace', function () use ($ownerOn, $clickNewWorkspaceInSidebar) {
    $user = $ownerOn(Slug::Workspaces);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $clickNewWorkspaceInSidebar($page);

    waitForPath($page, parse_url(route('app.workspaces.create'), PHP_URL_PATH));
    waitFor($page, '@workspaces-create-name');
    $page->assertMissing('@workspace-upgrade-dialog');

    $page->fill('@workspaces-create-name', 'Second')
        ->click('@workspaces-create-submit');

    waitForPath($page, parse_url(route('app.posts.index'), PHP_URL_PATH));
    $page->assertNoJavaScriptErrors();

    Queue::assertNotPushed(CaptureAccountEvent::class);
    expect(Workspace::where('name', 'Second')->where('account_id', $user->account_id)->exists())->toBeTrue();
});
