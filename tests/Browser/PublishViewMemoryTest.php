<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create(['timezone' => 'UTC', 'locale' => Locale::DEFAULT]);
    $workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($this->user->account);
    $this->user = $this->user->fresh();
    connectAiTo($this->user);

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $this->insightsPath = route('app.insights', absolute: false);

    $this->actingAs($this->user);
});

test('the publish menu link opens the list until another view is picked', function () {
    $publishLink = route('app.posts.index', absolute: false);

    $page = visit(route('app.insights'))->resize(1280, 900);
    waitFor($page, "[data-testid=\"nav-{$publishLink}\"]");

    $page->click("@nav-{$publishLink}");
    waitForPath($page, route('app.posts.index', absolute: false));

    $page->assertNoJavaScriptErrors();
});

test('the publish menu link reopens the board after leaving it', function () {
    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, '@board-column-draft');

    expect(browserStorageItem($page, $this->user, 'publish.view'))->toBe('board');

    $page->click("@nav-{$this->insightsPath}");
    waitForPath($page, route('app.insights', absolute: false));

    $boardPath = route('app.posts.board', absolute: false);
    $page->click("@nav-{$boardPath}");
    waitForPath($page, route('app.posts.board', absolute: false));
    waitFor($page, '@board-column-draft');

    $page->assertNoJavaScriptErrors();
});

test('a channel publish link reopens the calendar after leaving it', function () {
    $page = visit(route('app.channels.calendar', ['account' => $this->channel, 'view' => 'week']))->resize(1280, 900);
    waitFor($page, '@schedule-view-calendar');

    expect(browserStorageItem($page, $this->user, 'publish.view'))->toBe('calendar');

    $page->click("@nav-{$this->insightsPath}");
    waitForPath($page, route('app.insights', absolute: false));

    $page->click("@sidebar-channel-{$this->channel->id}");
    waitForPath($page, route('app.channels.calendar', ['account' => $this->channel, 'view' => 'week'], absolute: false));

    $page->assertNoJavaScriptErrors();
});

test('a link to one post on the list keeps the view the user picked', function () {
    $post = Post::factory()->forAccount($this->channel)->draft()->create(['user_id' => $this->user->id]);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, '@board-column-draft');

    $page->navigate(route('app.posts.index', ['post' => $post->id]));
    waitFor($page, "@post-card-{$post->id}");

    expect(browserStorageItem($page, $this->user, 'publish.view'))->toBe('board');
    $page->assertNoJavaScriptErrors();
});

test('the command palette opens a channel in the view the user picked', function () {
    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, '@board-column-draft');

    $page->keys('@app-content-shell', 'ControlOrMeta+k');
    waitFor($page, "@command-palette-item-channel-{$this->channel->id}");
    $page->click("@command-palette-item-channel-{$this->channel->id}");

    waitForPath($page, route('app.channels.board', $this->channel, absolute: false));
    $page->assertNoJavaScriptErrors();
});
