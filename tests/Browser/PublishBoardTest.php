<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

test('a card action brings a tag created meanwhile in as a column and drops a deleted one', function () {
    $alpha = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Alpha']);
    $gone = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Gone']);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board', ['group' => 'tag']))->resize(1280, 900);
    waitFor($page, "@board-column-untagged @board-card-{$this->draft->id}");
    waitFor($page, "@board-column-label_{$gone->id}");

    $added = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Added']);
    $gone->delete();

    $page->click("@post-labels-board-{$this->draft->id}-trigger");
    waitFor($page, "@post-labels-board-{$this->draft->id}-checkbox-{$alpha->id}");
    $page->click("@post-labels-board-{$this->draft->id}-checkbox-{$alpha->id}");

    waitFor($page, "@board-column-label_{$alpha->id} @board-card-{$this->draft->id}");
    waitFor($page, "@board-column-empty-label_{$added->id}");
    waitForGone($page, "@board-column-label_{$gone->id}");

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

test('a pending card reads Pending and splits its approval buttons across the row', function () {
    $pending = Post::factory()->forAccount($this->channel)->pendingApproval()->create([
        'user_id' => $this->user->id,
        'scheduled_at' => now()->addDays(2),
    ]);
    $requester = workspaceMember($this->workspace, 'approval');
    $request = Post::factory()->forAccount($this->channel)->pendingApproval()->create([
        'user_id' => $requester->id,
        'approval_requested_by' => $requester->id,
    ]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board', ['group' => 'channel']))->resize(1280, 900);
    waitFor($page, "@post-reject-board-{$pending->id}");

    $page->assertSeeIn("@board-card-status-{$pending->id}", __('posts.status.pending_approval'));

    $widths = $page->script("(() => { const row = document.querySelector('[data-testid=\"board-card-approval-{$pending->id}\"]').getBoundingClientRect(); const approve = document.querySelector('[data-testid=\"post-approve-board-{$pending->id}\"]').getBoundingClientRect(); const reject = document.querySelector('[data-testid=\"post-reject-board-{$pending->id}\"]').getBoundingClientRect(); return { equal: Math.abs(approve.width - reject.width) < 1, fills: Math.abs(approve.width + reject.width + 8 - row.width) < 1 }; })()");

    expect($widths)->toBe(['equal' => true, 'fills' => true]);

    $this->actingAs($requester);

    $own = visit(route('app.posts.board', ['group' => 'channel']))->resize(1280, 900);
    waitFor($own, "@post-revert-board-{$request->id}");

    $fills = $own->script("(() => { const row = document.querySelector('[data-testid=\"board-card-approval-{$request->id}\"]').getBoundingClientRect(); const revert = document.querySelector('[data-testid=\"post-revert-board-{$request->id}\"]').getBoundingClientRect(); return Math.abs(revert.width - row.width) < 1; })()");

    expect($fills)->toBeTrue();
    $own->assertMissing("@post-approve-board-{$request->id}")
        ->assertMissing("@post-reject-board-{$request->id}")
        ->assertMissing("@board-card-{$pending->id}")
        ->assertNoJavaScriptErrors();
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

test('deleting a card from its menu reloads the columns in place', function () {
    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-column-draft @board-card-{$this->draft->id}");
    waitFor($page, '@board-column-scroll-failed');
    $page->script('document.querySelector(\'[data-testid="board-column-scroll-failed"]\').dataset.mounted = "before"');

    $page->click("@board-card-menu-{$this->draft->id}");
    waitForOpen($page, "@post-card-menu-content-board-{$this->draft->id}");
    $page->click("@post-delete-board-{$this->draft->id}");
    waitForOpen($page, '@confirm-delete-modal');
    $page->click('@confirm-delete-action');

    waitForGone($page, "@board-card-{$this->draft->id}");

    expect($page->script('document.querySelector(\'[data-testid="board-column-scroll-failed"]\')?.dataset.mounted'))->toBe('before')
        ->and(Post::query()->whereKey($this->draft->id)->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('publishing now from a board card\'s details reloads the columns in place', function () {
    Queue::fake([PublishPost::class]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $scheduled = Post::factory()->forAccount($this->channel)->scheduled()->create([
        'user_id' => $this->user->id,
        'scheduled_at' => now()->addDays(2),
        'content' => 'Ready to go out',
    ]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board', ['group' => 'tag']))->resize(1280, 900);
    waitForText($page, "@board-card-status-{$scheduled->id}", __('posts.status.scheduled'));
    waitFor($page, "@board-column-scroll-label_{$label->id}");
    $page->script("document.querySelector('[data-testid=\"board-column-scroll-label_{$label->id}\"]').dataset.mounted = 'before'");

    $page->click("@board-card-open-{$scheduled->id}");
    waitFor($page, "@post-details-publish-now-{$scheduled->id}");
    $page->click("@post-details-publish-now-{$scheduled->id}");

    waitForText($page, "@board-card-status-{$scheduled->id}", __('posts.status.publishing'));

    expect($page->script("document.querySelector('[data-testid=\"board-column-scroll-label_{$label->id}\"]')?.dataset.mounted"))->toBe('before')
        ->and($scheduled->refresh()->status)->toBe(PostStatus::Publishing);
    $page->assertNoJavaScriptErrors();
});

test('a draft without a time reads No time like the list, a dated one shows its time', function () {
    $this->draft->update(['scheduled_at' => null]);
    $dated = Post::factory()->forAccount($this->channel)->draft()->create([
        'user_id' => $this->user->id,
        'scheduled_at' => now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(14)->setTime(10, 0),
    ]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-card-no-time-{$this->draft->id}");

    $page->assertSeeIn("@board-card-no-time-{$this->draft->id}", __('posts.publish.no_time'))
        ->assertMissing("@board-card-time-{$this->draft->id}")
        ->assertVisible("@board-card-time-{$dated->id}")
        ->assertMissing("@board-card-no-time-{$dated->id}")
        ->assertNoJavaScriptErrors();
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

test('a thousand posts or more show as a short count on the board column and the list tab, the same in every language', function () {
    $this->user->update(['locale' => Locale::PortugueseBrazil]);
    $published = Post::factory()->forAccount($this->channel)->published()->create(['user_id' => $this->user->id]);
    $row = Arr::except((array) DB::table('posts')->where('id', $published->id)->first(), ['id']);

    collect(range(1, 1199))
        ->map(fn (): array => [...$row, 'id' => (string) Str::uuid()])
        ->chunk(200)
        ->each(fn (Collection $rows) => DB::table('posts')->insert($rows->all()));

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitForText($page, '@board-column-count-published', '1.2K');

    $page->assertAttribute('@board-column-count-published', 'title', '1200')
        ->assertSeeIn('@board-column-count-draft', '1')
        ->assertNoJavaScriptErrors();

    $list = visit(route('app.posts.index'))->resize(1280, 900);
    waitForText($list, '@publish-tab-count-sent', '1.2K');

    $list->assertSeeIn('@publish-tab-count-drafts', '1')
        ->assertNoJavaScriptErrors();
});

test('a post created, published or deleted elsewhere reaches the open board without a reload', function () {
    $scheduled = Post::factory()->forAccount($this->channel)->scheduled()->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-column-scheduled @board-card-{$scheduled->id}");
    waitForCondition($page, "Boolean(window.Pusher?.instances[0]?.channels.channels['private-workspace.{$this->workspace->id}'])");

    $emit = fn (string $event) => $page->script(<<<JS
        window.Pusher.instances[0].channels.channels['private-workspace.{$this->workspace->id}'].emit('{$event}', {})
    JS);

    $created = Post::factory()->forAccount($this->channel)->draft()->create(['user_id' => $this->user->id]);
    $emit('post.created');
    waitFor($page, "@board-column-draft @board-card-{$created->id}");

    $scheduled->markAsPublished();
    $emit('post.platform.status.updated');
    waitFor($page, "@board-column-published @board-card-{$scheduled->id}");
    waitForGone($page, "@board-column-scheduled @board-card-{$scheduled->id}");

    $this->draft->delete();
    $emit('post.deleted');
    waitForGone($page, "@board-card-{$this->draft->id}");

    $page->assertVisible("@board-card-{$created->id}")
        ->assertNoJavaScriptErrors();
});

test('a column loads its next posts as it scrolls', function () {
    config()->set('app.pagination.default', 2);
    Post::factory()->forAccount($this->channel)->draft()->count(4)->create(['user_id' => $this->user->id]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitForCount($page, '@board-column-draft [data-testid^="board-card-open-"]', 2);
    waitForText($page, '@board-column-count-draft', '5');

    $scrollDown = "document.querySelector('[data-testid=\"board-column-scroll-draft\"]').scrollTop = 100000";
    $page->script($scrollDown);
    waitForCount($page, '@board-column-draft [data-testid^="board-card-open-"]', 4);
    $page->script($scrollDown);
    waitForCount($page, '@board-column-draft [data-testid^="board-card-open-"]', 5);

    $page->assertNoJavaScriptErrors();
});

test('a card opens its details from the keyboard', function () {
    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-card-open-{$this->draft->id}");

    $page->keys("@board-card-open-{$this->draft->id}", 'Enter');

    waitForOpen($page, "@post-details-{$this->draft->id}");
    $page->assertVisible("@post-details-{$this->draft->id}")
        ->assertNoJavaScriptErrors();
});

test('approving from a board card shows the button waiting until the answer lands', function () {
    $pending = Post::factory()->forAccount($this->channel)->pendingApproval()->create([
        'user_id' => $this->user->id,
        'content' => '<p>Ready for review</p>',
        'scheduled_at' => now()->addDays(2),
    ]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@post-approve-board-{$pending->id}");

    $page->script(<<<'JS'
        (() => {
            const send = XMLHttpRequest.prototype.send;
            window.__heldRequests = [];
            XMLHttpRequest.prototype.send = function (body) {
                window.__heldRequests.push(() => send.call(this, body));
            };
            window.__releaseRequests = () => {
                XMLHttpRequest.prototype.send = send;
                window.__heldRequests.splice(0).forEach((release) => release());
            };
        })()
    JS);

    $page->click("@post-approve-board-{$pending->id}");
    waitForCondition($page, 'window.__heldRequests.length === 1');
    waitForState($page, "@post-approve-board-{$pending->id}", 'element.disabled');

    expect($pending->fresh()->status)->toBe(PostStatus::PendingApproval);

    $page->script('window.__releaseRequests()');
    waitFor($page, "@board-column-scheduled @board-card-{$pending->id}");
    expect($pending->fresh()->status)->toBe(PostStatus::Scheduled);
    $page->assertNoJavaScriptErrors();
});

test('the recurrence opened for another channel of the post from its details reads that channel\'s time zone', function () {
    $newYork = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'position' => 2,
        'timezone' => 'America/New_York',
    ]);
    $this->channel->update(['timezone' => 'UTC']);
    $at = now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(14)->setTime(2, 0);
    $group = (string) Str::uuid();
    $first = Post::factory()->forAccount($this->channel)->scheduled()->create(['user_id' => $this->user->id, 'post_group_id' => $group, 'scheduled_at' => $at]);
    $sibling = Post::factory()->forAccount($newYork)->scheduled()->create(['user_id' => $this->user->id, 'post_group_id' => $group, 'scheduled_at' => $at]);

    $this->actingAs($this->user);

    $page = visit(route('app.posts.board'))->resize(1280, 900);
    waitFor($page, "@board-card-open-{$first->id}");
    $page->click("@board-card-open-{$first->id}");
    waitForOpen($page, "@post-details-{$first->id}");

    waitFor($page, "@post-details-rail-item-{$sibling->id}");
    $page->click("@post-details-rail-item-{$sibling->id}");
    waitFor($page, "@post-card-menu-details-{$sibling->id}");
    $page->click("@post-card-menu-details-{$sibling->id}");
    waitFor($page, "@post-recurrence-open-details-{$sibling->id}");
    $page->click("@post-recurrence-open-details-{$sibling->id}");
    waitFor($page, "@post-recurrence-summary-{$first->id}");

    $local = $at->setTimezone('America/New_York');
    waitForText($page, "@post-recurrence-summary-{$first->id}", "every {$local->format('l')} at {$local->format('g:i A')}");
    $page->assertNoJavaScriptErrors();
});

test('compose on the board opens the composer there and closing it stays on the board with its grouping', function () {
    $this->actingAs($this->user);

    $page = visit(route('app.posts.board', ['compose' => 1, 'group' => 'tag']))->resize(1280, 900);
    waitFor($page, '@post-composer-dialog');

    $page->assertPathIs(route('app.posts.board', absolute: false));
    waitForCondition($page, "new URLSearchParams(location.search).get('group') === 'tag'");

    $page->click('@composer-close');
    waitForGone($page, '@post-composer-dialog');
    waitFor($page, '@board-column-untagged');

    $page->assertPathIs(route('app.posts.board', absolute: false))
        ->assertNoJavaScriptErrors();
    expect($page->script('location.search'))->toContain('group=tag');
});

test('editing a post from its board card lands back on the board after closing or saving', function (string $leave) {
    $this->actingAs($this->user);
    $before = route('app.settings.preferences', absolute: false);
    $boardPath = route('app.posts.board', absolute: false);

    $page = visit(route('app.settings.preferences'))->resize(1280, 900)->navigate(route('app.posts.board'));
    waitFor($page, "@board-card-open-{$this->draft->id}");
    $page->click("@board-card-open-{$this->draft->id}");
    waitFor($page, "@post-details-edit-{$this->draft->id}");
    $page->click("@post-details-edit-{$this->draft->id}");
    waitFor($page, '@post-composer-dialog');

    $page->click($leave);
    waitForGone($page, '@post-composer-dialog');
    waitForPath($page, $boardPath);
    waitForCondition($page, "!location.search.includes('edit=')");
    waitFor($page, "@board-card-{$this->draft->id}");

    $page->assertPathIs($boardPath)->assertMissing('@unsaved-changes-dialog');

    $page->back();
    waitForPath($page, $before);

    $page->assertPathIs($before)->assertNoJavaScriptErrors();
})->with([
    'closing' => '@composer-close',
    'saving' => '@composer-save-draft',
]);
