<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

function boardInsideViewport(): string
{
    return 'element.getBoundingClientRect().width > 0 && element.getBoundingClientRect().top >= 0 && element.getBoundingClientRect().left >= 0 && element.getBoundingClientRect().right <= window.innerWidth + 1 && element.getBoundingClientRect().bottom <= window.innerHeight + 1';
}

beforeEach(function () {
    $this->user = User::factory()->create(['timezone' => 'UTC', 'locale' => Locale::DEFAULT]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);
    $this->user = $this->user->fresh();
    connectAiTo($this->user);

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'position' => 1,
    ]);
    $this->draft = Post::factory()->forAccount($this->channel)->draft()->create(['user_id' => $this->user->id]);
});

test('the list links to the board and the board groups by status', function () {
    $this->actingAs($this->user);

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitFor($page, '@schedule-view-board');

    $page->click('@schedule-view-board');

    waitForPath($page, route('app.posts.board', absolute: false));
    waitFor($page, '@board-column-draft');

    $page->assertVisible('@board-column-draft')
        ->assertVisible('@board-column-approvals')
        ->assertVisible('@board-column-scheduled')
        ->assertVisible('@board-column-published')
        ->assertVisible('@board-column-failed');

    waitFor($page, "@board-column-draft @board-card-{$this->draft->id}");

    $page->assertNoJavaScriptErrors();
});

test('group by tag shows a post in each of its tags', function () {
    $alpha = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Alpha']);
    $beta = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Beta']);
    $this->draft->labels()->attach([$alpha->id, $beta->id]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitForCount($page, '@board-group-trigger', 1);
    waitForState($page, '@board-group-trigger', 'element.getBoundingClientRect().height > 0');

    $page->click('@board-group-trigger');

    waitForOpen($page, '@board-group-content');
    waitForState($page, '@board-group-content', boardInsideViewport());

    $page->click('@board-group-option-tag');

    waitFor($page, "@board-column-label_{$alpha->id}");
    waitFor($page, "@board-column-label_{$alpha->id} @board-card-{$this->draft->id}");
    waitFor($page, "@board-column-label_{$beta->id} @board-card-{$this->draft->id}");
    waitForCount($page, "@board-card-{$this->draft->id}", 2);

    $page->assertVisible("@board-column-label_{$beta->id}")
        ->assertNoJavaScriptErrors();
});

test('a card opens the post details', function () {
    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-card-open-{$this->draft->id}");

    $page->click("@board-card-open-{$this->draft->id}");

    waitForOpen($page, "@post-details-{$this->draft->id}");
    waitForState($page, "@post-details-{$this->draft->id}", boardInsideViewport());

    $page->assertVisible("@post-details-{$this->draft->id}")
        ->assertNoJavaScriptErrors();
});

test('on a phone the group menu is a bottom sheet', function () {
    $channelTwo = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon,
        'position' => 2,
    ]);
    Post::factory()->forAccount($channelTwo)->draft()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(390, 844);
    waitForCount($page, '@board-group-trigger', 1);
    waitForState($page, '@board-group-trigger', 'element.getBoundingClientRect().height > 0');

    $page->click('@board-group-trigger');

    waitForOpen($page, '@board-group-sheet');
    waitForState($page, '@board-group-sheet', boardInsideViewport());

    $page->click('@board-group-option-channel');

    waitFor($page, "@board-column-channel_{$this->channel->id}");

    $page->assertVisible("@board-column-channel_{$this->channel->id}")
        ->assertNoJavaScriptErrors();
});

test('the channel filter, the label filter and a card menu open inside the viewport', function () {
    WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Alpha']);
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Mastodon,
        'position' => 2,
    ]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, '@posts-channel-filter');

    $page->click('@posts-channel-filter');
    waitFor($page, '@posts-channel-toggle-all');
    waitForState($page, '@posts-channel-toggle-all', boardInsideViewport());
    $page->click('@posts-channel-filter');
    waitForGone($page, '@posts-channel-toggle-all');

    $page->click('@posts-label-filter');
    waitFor($page, '@posts-label-untagged');
    waitForState($page, '@posts-label-untagged', boardInsideViewport());
    $page->click('@posts-label-filter');
    waitForGone($page, '@posts-label-untagged');

    $page->click("@board-card-menu-{$this->draft->id}");
    waitForOpen($page, "@post-card-menu-content-board-{$this->draft->id}");
    waitForState($page, "@post-card-menu-content-board-{$this->draft->id}", boardInsideViewport());

    $page->assertNoJavaScriptErrors();
});

test('a card action reloads the columns, so a tagged card moves to its tag', function () {
    $alpha = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Alpha']);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board', ['group' => 'tag']))->resize(1280, 900);
    waitFor($page, "@board-column-untagged @board-card-{$this->draft->id}");
    $page->assertMissing("@board-column-label_{$alpha->id} @board-card-{$this->draft->id}");

    $page->click("@post-labels-board-{$this->draft->id}-trigger");
    waitFor($page, "@post-labels-board-{$this->draft->id}-checkbox-{$alpha->id}");
    $page->click("@post-labels-board-{$this->draft->id}-checkbox-{$alpha->id}");

    waitFor($page, "@board-column-label_{$alpha->id} @board-card-{$this->draft->id}");
    waitForGone($page, "@board-column-untagged @board-card-{$this->draft->id}");
    waitForText($page, "@board-column-count-label_{$alpha->id}", '1');

    expect($this->draft->labels()->pluck('workspace_labels.id')->all())->toBe([$alpha->id]);
    $page->assertNoJavaScriptErrors();
});

test('an approver approves a pending post from its board card', function () {
    $pending = Post::factory()->forAccount($this->channel)->pendingApproval()->create([
        'user_id' => $this->user->id,
        'content' => '<p>Ready for review</p>',
    ]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-column-approvals @board-card-{$pending->id}");

    $page->click("@post-approve-board-{$pending->id}");

    waitFor($page, "@board-column-scheduled @board-card-{$pending->id}");
    waitForGone($page, "@board-column-approvals @board-card-{$pending->id}");

    waitForText($page, '@post-approval-toast', __('posts.approvals.toasts.scheduled'));

    expect($pending->fresh()->status)->toBe(PostStatus::Scheduled);
    $page->assertNoJavaScriptErrors();
});

test('an approver rejects a pending post from its board card', function () {
    $pending = Post::factory()->forAccount($this->channel)->pendingApproval()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-column-approvals @board-card-{$pending->id}");

    $page->click("@post-reject-board-{$pending->id}");

    waitFor($page, "@board-column-draft @board-card-{$pending->id}");
    waitForGone($page, "@board-column-approvals @board-card-{$pending->id}");

    waitForText($page, '@post-approval-toast', __('posts.approvals.toasts.rejected'));

    expect($pending->fresh()->status)->toBe(PostStatus::Draft);
    $page->assertNoJavaScriptErrors();
});

test('a label filter matching no column says so instead of an empty board', function () {
    $this->actingAs($this->user);

    $page = visit(route('app.posts.board', ['group' => 'tag', 'labels' => [(string) str()->uuid()]]))->resize(1280, 900);
    waitFor($page, '@board-no-columns');

    $page->assertSeeIn('@board-no-columns', __('posts.board.no_columns.title'))
        ->assertNoJavaScriptErrors();
});

test('moving a scheduled post to drafts from its board card menu reloads the columns in place', function () {
    $scheduled = Post::factory()->forAccount($this->channel)->scheduled()->create([
        'user_id' => $this->user->id,
        'scheduled_at' => now()->addDays(2),
    ]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-column-scheduled @board-card-{$scheduled->id}");
    $page->script('document.querySelector(\'[data-testid="board-column-failed"]\').dataset.mounted = "before"');

    $page->click("@board-card-menu-{$scheduled->id}");
    waitForOpen($page, "@post-card-menu-content-board-{$scheduled->id}");
    $page->click("@post-move-drafts-board-{$scheduled->id}");

    waitFor($page, "@board-column-draft @board-card-{$scheduled->id}");
    waitForGone($page, "@board-column-scheduled @board-card-{$scheduled->id}");

    expect($page->script('document.querySelector(\'[data-testid="board-column-failed"]\').dataset.mounted'))->toBe('before');
    $page->assertNoJavaScriptErrors();
});

test('a video thumbnail loads only once its card comes near the screen', function () {
    $video = Post::factory()->forAccount($this->channel)->failed()->create([
        'user_id' => $this->user->id,
        'media' => [['id' => (string) str()->uuid(), 'path' => 'media/clip.mp4', 'url' => 'https://cdn.test/clip.mp4', 'type' => 'video', 'mime_type' => 'video/mp4']],
    ]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(390, 844);
    waitFor($page, "@board-card-thumbnail-{$video->id}");

    expect($page->script("document.querySelectorAll('[data-testid=\"board-card-thumbnail-{$video->id}\"] video').length"))->toBe(0);

    $page->script('document.querySelector(\'[data-testid="board-column-failed"]\').scrollIntoView({ inline: "start" })');
    waitFor($page, "@board-card-thumbnail-{$video->id} video");

    $page->assertNoJavaScriptErrors();
});
