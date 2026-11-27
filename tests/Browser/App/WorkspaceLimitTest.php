<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Jobs\PostHog\TrackWorkspaceLimitHit;
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
    Queue::fake([TrackWorkspaceLimitHit::class]);
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

    Queue::assertPushedTimes(TrackWorkspaceLimitHit::class, 1);
    Queue::assertPushed(TrackWorkspaceLimitHit::class, fn (TrackWorkspaceLimitHit $job) => $job->userId === (string) $user->id);
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

    Queue::assertNotPushed(TrackWorkspaceLimitHit::class);
    expect(Workspace::where('name', 'Second')->where('account_id', $user->account_id)->exists())->toBeTrue();
});

test('at the cap without a current workspace, submitting the create page opens the upgrade dialog in place', function () use ($ownerOn) {
    $user = $ownerOn(Slug::Socials);
    $user->update(['current_workspace_id' => null]);
    $this->actingAs($user->fresh());

    $page = visit(route('app.workspaces.create'));
    waitFor($page, '@workspaces-create-name');

    $page->fill('@workspaces-create-name', 'Second')
        ->click('@workspaces-create-submit');

    waitFor($page, '@workspace-upgrade-dialog');
    $page->assertVisible('@workspace-upgrade-dialog')
        ->assertPathIs(parse_url(route('app.workspaces.create'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();

    expect($user->account->workspaces()->count())->toBe(1);
});
