<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\User\TimeFormat;
use App\Exceptions\Social\ErrorCategory;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonInterface;

function waitForPublishQueueTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForPublishQueueCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForPublishQueueOrder(mixed $page, Post $first, Post $second): void
{
    for ($attempt = 0; $attempt < 50 && ! $first->refresh()->scheduled_at->lessThan($second->refresh()->scheduled_at); $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount}
 */
function publishQueueSetup(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, '09:00')->withTime($day, '12:00')->withTime($day, '18:00');
    }

    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => $schedule,
    ]);

    return [$user, $workspace, $channel];
}

function publishQueuePost(User $user, SocialAccount $channel, QueuePosition $position = QueuePosition::Next): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => null,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
    ]);

    ReflowChannelQueue::handle($channel, $post, $position);

    return $post->refresh();
}

function publishQueueSlotKey(SocialAccount $channel, CarbonInterface $slot): string
{
    return "{$channel->id}-{$slot->getTimestamp()}";
}

function publishQueueVisitWithCleanStorage(string $url): mixed
{
    $page = visit($url);
    $page->script('window.localStorage.clear()');

    return $page->refresh();
}

test('a slot opens the composer at that slot and saving places the post there as a queue post', function () {
    [$user, , $channel] = publishQueueSetup();
    $user->update(['time_format' => TimeFormat::TwentyFourHour]);
    $slot = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 2)[1];
    $slotKey = publishQueueSlotKey($channel, $slot);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPublishQueueTestId($page, "queue-slot-{$slotKey}");

    $page->assertVisible("@queue-day-{$slot->utc()->format('Y-m-d')}")
        ->assertVisible("@queue-slot-{$slotKey}")
        ->click("@queue-slot-new-{$slotKey}");

    waitForPublishQueueTestId($page, "composer-caption-{$channel->id}");
    $page->fill("@composer-caption-{$channel->id}", 'From an empty slot');
    waitForPublishQueueTestId($page, 'composer-submit');
    $label = $slot->format($slot->year === now('UTC')->year ? 'M j' : 'M j, Y').", {$slot->format('H:i')}";

    expect($page->script('document.querySelector(\'[data-testid="composer-submit"]\').dataset.scheduleMode'))->toBe('custom')
        ->and(trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-trigger]").textContent')))->toBe($label);

    $page->click('@composer-submit');
    waitForPublishQueueCondition($page, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');

    $post = Post::query()->where('workspace_id', $channel->workspace_id)->sole();

    expect($post->scheduled_at->equalTo($slot))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($post->status)->toBe(PostStatus::Scheduled);
    $page->assertNoJavaScriptErrors();
});

test('a member whose posts need approval can still open the composer from an empty slot', function () {
    [, $workspace, $channel] = publishQueueSetup();
    $requester = workspaceMember($workspace, 'approval', ['timezone' => 'UTC']);
    $slot = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 1)[0];
    $slotKey = publishQueueSlotKey($channel, $slot);
    $this->actingAs($requester);

    $page = visit(route('app.posts.index'));
    waitForPublishQueueTestId($page, "queue-slot-new-{$slotKey}");

    expect($page->script("(() => { const slot = document.querySelector('[data-testid=\"queue-slot-new-{$slotKey}\"]'); const icon = slot.querySelector('svg[data-testid=\"queue-slot-icon-{$slotKey}\"]'); return [icon !== null, slot.querySelector('img') === null, icon && getComputedStyle(icon).color]; })()"))
        ->toBe([true, true, 'rgb(10, 102, 194)']);

    $page->assertSeeIn("@queue-slot-new-{$slotKey}", 'New')
        ->click("@queue-slot-new-{$slotKey}");

    waitForPublishQueueTestId($page, "composer-caption-{$channel->id}");
    $page->assertVisible("@composer-caption-{$channel->id}")
        ->assertNoJavaScriptErrors();
});

test('scheduled posts replace the slots with a list of only those posts', function () {
    [$user, , $channel] = publishQueueSetup();
    $queued = publishQueuePost($user, $channel);
    $custom = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->utc()->addDays(120)->setTime(15, 30),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $custom->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
    ]);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.posts.index'));
    waitForPublishQueueTestId($page, "post-card-{$custom->id}");

    $page->assertVisible("@post-card-{$queued->id}")
        ->assertPresent("@queue-day-{$custom->scheduled_at->format('Y-m-d')}")
        ->assertAttribute("@post-schedule-mode-{$custom->id}", 'data-mode', 'custom')
        ->assertMissing("@post-schedule-mode-{$queued->id}")
        ->assertMissing('@queue-more-times');

    expect($page->script('document.querySelectorAll(\'[data-testid^="queue-slot-"]\').length'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('move down from the card menu swaps a queued post with the next one', function () {
    [$user, , $channel] = publishQueueSetup();
    $a = publishQueuePost($user, $channel);
    $b = publishQueuePost($user, $channel);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "post-card-menu-{$a->id}");
    $page->click("@post-card-menu-{$a->id}");
    waitForPublishQueueTestId($page, "post-move-down-{$a->id}");
    $page->click("@post-move-down-{$a->id}");
    waitForPublishQueueOrder($page, $b, $a);

    expect($b->refresh()->scheduled_at->lessThan($a->refresh()->scheduled_at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('dragging a queued post above another reorders the queue', function () {
    [$user, , $channel] = publishQueueSetup();
    $a = publishQueuePost($user, $channel);
    $b = publishQueuePost($user, $channel);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "post-drag-handle-{$b->id}");
    $page->drag("@post-card-{$b->id}", "@post-card-{$a->id}");
    waitForPublishQueueOrder($page, $b, $a);

    expect($b->refresh()->scheduled_at->lessThan($a->refresh()->scheduled_at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a stale reorder is rolled back and reported', function () {
    [$user, , $channel] = publishQueueSetup();
    $a = publishQueuePost($user, $channel);
    $b = publishQueuePost($user, $channel);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "post-card-menu-{$a->id}");
    $top = publishQueuePost($user, $channel, QueuePosition::Top);
    $page->click("@post-card-menu-{$a->id}");
    waitForPublishQueueTestId($page, "post-move-down-{$a->id}");
    $page->click("@post-move-down-{$a->id}");
    waitForPublishQueueTestId($page, 'queue-reorder-error-toast');

    $page->assertSeeIn('@queue-reorder-error-toast', __('posts.errors.queue_order_stale'));
    waitForPublishQueueTestId($page, "post-card-{$top->id}");
    $page->assertVisible("@post-card-{$top->id}");
    expect($page->script("document.querySelector('[data-post-id=\"{$a->id}\"]').compareDocumentPosition(document.querySelector('[data-post-id=\"{$b->id}\"]')) & Node.DOCUMENT_POSITION_FOLLOWING"))->toBeGreaterThan(0)
        ->and($a->refresh()->scheduled_at->lessThan($b->refresh()->scheduled_at))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the posting times toggle hides every slot and survives a reload', function () {
    [$user, , $channel] = publishQueueSetup();
    $slot = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 1)[0];
    $slotKey = publishQueueSlotKey($channel, $slot);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "queue-slot-{$slotKey}");
    $page->click('@publish-menu');
    waitForPublishQueueTestId($page, 'publish-toggle-slots');
    $page->click('@publish-toggle-slots');
    waitForPublishQueueCondition($page, '!document.querySelector(\'[data-testid^="queue-slot-"]\')');

    expect($page->script('document.querySelectorAll(\'[data-testid^="queue-slot-"]\').length'))->toBe(0);

    $page->refresh();
    waitForPublishQueueTestId($page, 'publish-page');

    expect($page->script('document.querySelectorAll(\'[data-testid^="queue-slot-"]\').length'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('a far display time zone regroups slots under its own calendar day', function () {
    [$user, , $channel] = publishQueueSetup();
    $slot = now()->utc()->addDay()->setTime(18, 0);
    $slotKey = publishQueueSlotKey($channel, $slot);
    $utcDay = $slot->format('Y-m-d');
    $aucklandDay = $slot->setTimezone('Pacific/Auckland')->format('Y-m-d');
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.posts.index'));
    waitForPublishQueueTestId($page, "queue-slot-{$slotKey}");

    expect($page->script("!!document.querySelector('[data-testid=\"queue-day-{$utcDay}\"] [data-testid=\"queue-slot-{$slotKey}\"]')"))->toBeTrue();

    $page->click('@publish-timezone-trigger');
    waitForPublishQueueTestId($page, 'publish-timezone-search');
    $page->type('@publish-timezone-search', 'Auckland');
    waitForPublishQueueTestId($page, 'publish-timezone-option-Pacific-Auckland');
    $page->click('@publish-timezone-option-Pacific-Auckland');
    waitForPublishQueueCondition($page, "!!document.querySelector('[data-testid=\"queue-day-{$aucklandDay}\"] [data-testid=\"queue-slot-{$slotKey}\"]')");

    expect($page->script('new URLSearchParams(location.search).get("tz")'))->toBe('Pacific/Auckland')
        ->and($page->script("!!document.querySelector('[data-testid=\"queue-day-{$aucklandDay}\"] [data-testid=\"queue-slot-{$slotKey}\"]')"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('changing the time zone keeps a tab that came from a notes link', function () {
    [$user, , $channel] = publishQueueSetup();
    $draft = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $draft->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
    ]);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.posts.index', ['notes' => $draft->id]));
    waitForPublishQueueTestId($page, "post-card-{$draft->id}");
    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");

    $page->click('@publish-timezone-trigger');
    waitForPublishQueueTestId($page, 'publish-timezone-search');
    $page->type('@publish-timezone-search', 'Auckland');
    waitForPublishQueueTestId($page, 'publish-timezone-option-Pacific-Auckland');
    $page->click('@publish-timezone-option-Pacific-Auckland');
    waitForPublishQueueCondition($page, 'new URLSearchParams(location.search).get("tz") === "Pacific/Auckland"');
    waitForPublishQueueTestId($page, "post-card-{$draft->id}");

    expect($page->script('new URLSearchParams(location.search).get("tab")'))->toBe('drafts')
        ->and($page->script('new URLSearchParams(location.search).has("notes")'))->toBeFalse();
    $page->assertVisible("@post-card-{$draft->id}")->assertNoJavaScriptErrors();
});

test('more times extends the queue range', function () {
    [$user] = publishQueueSetup();
    $farDay = now()->utc()->addDays(20)->format('Y-m-d');
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPublishQueueTestId($page, 'queue-more-times');
    $page->assertMissing("@queue-day-{$farDay}")
        ->click('@queue-more-times');
    waitForPublishQueueTestId($page, "queue-day-{$farDay}");

    $page->assertPresent("@queue-day-{$farDay}")
        ->assertNoJavaScriptErrors();
});

test('a failed post is listed in sent with its failed badge and actions, not in the queue', function () {
    [$user, $workspace, $channel] = publishQueueSetup();
    $failed = Post::factory()->failed()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'scheduled_at' => now()->subDays(21),
    ]);
    PostPlatform::factory()->failed()->create([
        'post_id' => $failed->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
        'error_message' => 'Video duration exceeds the 90 second limit.',
        'error_context' => ['category' => ErrorCategory::MediaFormat->value],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPublishQueueTestId($page, 'publish-tab-queue');

    $page->assertMissing("@post-card-{$failed->id}")
        ->assertMissing('@needs-attention')
        ->assertNoJavaScriptErrors();

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForPublishQueueTestId($page, "post-card-{$failed->id}");

    $page->assertSeeIn("@post-status-{$failed->id}", __('posts.status.failed'))
        ->assertSeeIn("@post-created-by-{$failed->id}", $user->name)
        ->assertMissing("@post-published-via-{$failed->id}")
        ->assertMissing("@post-edit-{$failed->id}");

    expect($page->script("document.querySelector('[data-testid=\"post-time-{$failed->id}\"]').getAttribute('datetime')"))
        ->toStartWith($failed->scheduled_at->toDateString());

    $page->click("@post-status-{$failed->id}");
    waitForPublishQueueTestId($page, "post-failure-{$failed->id}");

    $page->assertSeeIn("@post-failure-reason-{$failed->id}", __('posts.publish.failure.categories.media_format'))
        ->assertSeeIn("@post-failure-details-{$failed->id}", 'Video duration exceeds the 90 second limit.');

    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', {key: 'Escape', bubbles: true}))");
    waitForPublishQueueCondition($page, "!document.querySelector('[data-testid=\"post-failure-{$failed->id}\"]')");

    $page->click("@post-card-menu-{$failed->id}");
    waitForPublishQueueTestId($page, "post-duplicate-{$failed->id}");

    $page->assertPresent("@post-duplicate-{$failed->id}")
        ->assertPresent("@post-details-open-{$failed->id}")
        ->assertPresent("@post-delete-{$failed->id}")
        ->assertNoJavaScriptErrors();
});

function publishQueueCustomPost(User $user, SocialAccount $channel, CarbonInterface $at): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'content' => 'A post with its own time',
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => $at,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
    ]);

    return $post;
}

function publishQueueFollows(mixed $page, string $firstTestId, string $secondTestId): bool
{
    return (bool) $page->script("(document.querySelector('[data-testid=\"{$firstTestId}\"]').compareDocumentPosition(document.querySelector('[data-testid=\"{$secondTestId}\"]')) & Node.DOCUMENT_POSITION_FOLLOWING) > 0");
}

function waitForPublishQueueMode(mixed $page, Post $post, ScheduleMode $mode): void
{
    for ($attempt = 0; $attempt < 50 && $post->refresh()->schedule_mode !== $mode; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

test('a channel with one scheduled post shows it between its free posting times', function () {
    [$user, , $channel] = publishQueueSetup();
    $queued = publishQueuePost($user, $channel);
    [, $nextFree] = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 2);
    $nextFreeKey = publishQueueSlotKey($channel, $nextFree);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "queue-slot-{$nextFreeKey}");

    $page->assertVisible("@post-card-{$queued->id}")
        ->assertVisible("@post-drag-handle-{$queued->id}")
        ->assertVisible("@queue-slot-{$nextFreeKey}")
        ->assertMissing('@queue-slot-'.publishQueueSlotKey($channel, $queued->scheduled_at));

    expect(publishQueueFollows($page, "post-card-{$queued->id}", "queue-slot-{$nextFreeKey}"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the drag handle sits in the gutter beside the time and shows on hover', function () {
    [$user, , $channel] = publishQueueSetup();
    $queued = publishQueuePost($user, $channel);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "post-time-{$queued->id}");

    $handle = "document.querySelector('[data-testid=\"post-drag-handle-{$queued->id}\"]')";
    $time = "document.querySelector('[data-testid=\"post-time-{$queued->id}\"]')";
    $card = "document.querySelector('[data-testid=\"post-open-{$queued->id}\"]').closest('article')";

    expect($page->script("getComputedStyle({$handle}).opacity"))->toBe('0');

    $page->hover("@post-time-{$queued->id}");
    waitForPublishQueueCondition($page, "getComputedStyle({$handle}).opacity === '1'");

    expect($page->script("getComputedStyle({$handle}).opacity"))->toBe('1')
        ->and($page->script("{$handle}.getBoundingClientRect().left >= {$time}.getBoundingClientRect().right"))->toBeTrue()
        ->and($page->script("{$handle}.getBoundingClientRect().right <= {$card}.getBoundingClientRect().left"))->toBeTrue()
        ->and($page->script("Math.abs(({$handle}.getBoundingClientRect().top + {$handle}.getBoundingClientRect().bottom) / 2 - ({$time}.getBoundingClientRect().top + {$time}.getBoundingClientRect().bottom) / 2) <= 3"))->toBeTrue()
        ->and($page->script("getComputedStyle({$handle}).cursor"))->toBe('grab');
    $page->assertNoJavaScriptErrors();
});

test('the posting times toggle on a channel hides the free slots but keeps the posts', function () {
    [$user, , $channel] = publishQueueSetup();
    $queued = publishQueuePost($user, $channel);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "post-card-{$queued->id}");
    waitForPublishQueueCondition($page, '!!document.querySelector(\'[data-testid^="queue-slot-"]\')');
    $page->click('@publish-menu');
    waitForPublishQueueTestId($page, 'publish-toggle-slots');
    $page->click('@publish-toggle-slots');
    waitForPublishQueueCondition($page, '!document.querySelector(\'[data-testid^="queue-slot-"]\')');

    expect($page->script('document.querySelectorAll(\'[data-testid^="queue-slot-"]\').length'))->toBe(0);
    $page->assertVisible("@post-card-{$queued->id}")
        ->assertNoJavaScriptErrors();
});

test('dragging a post with its own time onto a free slot queues it at that slot', function () {
    [$user, , $channel] = publishQueueSetup();
    publishQueuePost($user, $channel);
    $slots = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 3);
    $custom = publishQueueCustomPost($user, $channel, $slots[2]->addMinutes(90));
    $targetKey = publishQueueSlotKey($channel, $slots[2]);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "post-drag-handle-{$custom->id}");
    waitForPublishQueueTestId($page, "queue-slot-{$targetKey}");
    $page->drag("@post-card-{$custom->id}", "@queue-slot-{$targetKey}");
    waitForPublishQueueMode($page, $custom, ScheduleMode::Queue);

    expect($custom->refresh()->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($custom->scheduled_at->equalTo($slots[2]))->toBeTrue();
    waitForPublishQueueCondition($page, "!document.querySelector('[data-testid=\"queue-slot-{$targetKey}\"]')");
    $page->assertMissing("@queue-slot-{$targetKey}")
        ->assertNoJavaScriptErrors();
});

test('dropping a post on a slot taken meanwhile is rolled back and reported', function () {
    [$user, , $channel] = publishQueueSetup();
    publishQueuePost($user, $channel);
    $slots = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 2);
    $custom = publishQueueCustomPost($user, $channel, $slots[1]->addMinutes(90));
    $targetKey = publishQueueSlotKey($channel, $slots[1]);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "queue-slot-{$targetKey}");
    waitForPublishQueueTestId($page, "post-drag-handle-{$custom->id}");
    publishQueuePost($user, $channel);
    $page->drag("@post-card-{$custom->id}", "@queue-slot-{$targetKey}");
    waitForPublishQueueTestId($page, 'queue-reorder-error-toast');

    $page->assertSeeIn('@queue-reorder-error-toast', __('posts.errors.queue_order_stale'));
    expect($custom->refresh()->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($custom->scheduled_at->equalTo($slots[1]->addMinutes(90)))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('dragging a queued post onto a later free slot keeps it there and leaves the others in place', function () {
    [$user, , $channel] = publishQueueSetup();
    $first = publishQueuePost($user, $channel);
    $first->update(['content' => 'First queued post']);
    $second = publishQueuePost($user, $channel);
    $secondAt = $second->scheduled_at;
    $slots = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 4);
    $targetKey = publishQueueSlotKey($channel, $slots[3]);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "queue-slot-{$targetKey}");
    waitForPublishQueueTestId($page, "post-card-{$first->id}");
    $page->drag("@post-card-{$first->id}", "@queue-slot-{$targetKey}");

    for ($attempt = 0; $attempt < 50 && ! $first->refresh()->scheduled_at->equalTo($slots[3]); $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }

    expect($first->refresh()->scheduled_at->equalTo($slots[3]))->toBeTrue()
        ->and($first->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($second->refresh()->scheduled_at->equalTo($secondAt))->toBeTrue();
    waitForPublishQueueTestId($page, 'queue-slot-'.publishQueueSlotKey($channel, $slots[0]));
    $page->assertPresent('@queue-slot-'.publishQueueSlotKey($channel, $slots[0]))
        ->assertNoJavaScriptErrors();
});

test('free slots follow the loaded posts beyond the default range', function () {
    config()->set('app.pagination.default', 2);
    [$user, , $channel] = publishQueueSetup();
    publishQueuePost($user, $channel);
    $far = publishQueueCustomPost($user, $channel, now()->utc()->addDays(20)->setTime(15, 30));
    publishQueueCustomPost($user, $channel, now()->utc()->addDays(40)->setTime(15, 30));
    $beyondDefault = now()->utc()->addDays(18)->setTime(9, 0);
    $slotKey = publishQueueSlotKey($channel, $beyondDefault);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "post-card-{$far->id}");
    waitForPublishQueueCondition($page, "!!document.querySelector('[data-testid=\"queue-slot-{$slotKey}\"]')");

    $page->assertPresent("@queue-slot-{$slotKey}");
    expect(publishQueueFollows($page, "queue-slot-{$slotKey}", "post-card-{$far->id}"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the all channels queue keeps its drag handle inline before the time', function () {
    [$user, , $channel] = publishQueueSetup();
    $first = publishQueuePost($user, $channel);
    publishQueuePost($user, $channel);
    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.posts.index'));
    waitForPublishQueueTestId($page, "post-drag-handle-{$first->id}");

    $handle = "document.querySelector('[data-testid=\"post-drag-handle-{$first->id}\"]')";
    $time = "document.querySelector('[data-testid=\"post-time-{$first->id}\"]')";

    expect($page->script("getComputedStyle({$handle}).opacity"))->toBe('1')
        ->and($page->script("getComputedStyle({$handle}).position"))->toBe('static')
        ->and($page->script("{$handle}.getBoundingClientRect().right <= {$time}.getBoundingClientRect().left"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('a pending queue request shows at its reserved slot and approving it keeps it there as a queued post', function () {
    [$user, $workspace, $channel] = publishQueueSetup();
    $requester = workspaceMember($workspace, 'approval');
    [$first, $reserved] = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 2);

    $pending = CreatePosts::execute($workspace, $requester, [
        'status' => 'scheduled',
        'content' => 'Waiting in its slot',
        'scheduled_at' => $reserved->toIso8601String(),
        'queue_slot' => $reserved->toIso8601String(),
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();

    expect($pending->status)->toBe(PostStatus::PendingApproval)
        ->and($pending->schedule_mode)->toBe(ScheduleMode::Queue);

    $this->actingAs($user);

    $page = publishQueueVisitWithCleanStorage(route('app.channels.publish', $channel));
    waitForPublishQueueTestId($page, "post-card-{$pending->id}");

    $page->assertVisible("@post-approval-badge-{$pending->id}")
        ->assertVisible("@post-approve-{$pending->id}")
        ->assertVisible("@post-reject-{$pending->id}")
        ->assertMissing("@post-drag-handle-{$pending->id}")
        ->assertMissing('@queue-slot-'.publishQueueSlotKey($channel, $reserved))
        ->assertVisible('@queue-slot-'.publishQueueSlotKey($channel, $first));

    expect($page->script("new Date(document.querySelector('[data-testid=\"post-time-{$pending->id}\"]').getAttribute('datetime')).getTime()"))
        ->toBe($reserved->getTimestamp() * 1000)
        ->and(publishQueueFollows($page, 'queue-slot-'.publishQueueSlotKey($channel, $first), "post-card-{$pending->id}"))->toBeTrue();

    $page->click("@post-approve-{$pending->id}");
    waitForPublishQueueCondition($page, "!document.querySelector('[data-testid=\"post-approval-badge-{$pending->id}\"]')");

    expect($pending->refresh()->status)->toBe(PostStatus::Scheduled)
        ->and($pending->scheduled_at->equalTo($reserved))->toBeTrue();

    waitForPublishQueueTestId($page, "post-card-{$pending->id}");
    $page->assertVisible("@post-card-{$pending->id}")
        ->assertMissing("@post-approval-badge-{$pending->id}")
        ->assertPresent("@post-drag-handle-{$pending->id}")
        ->assertNoJavaScriptErrors();
});

test('a failed post without a stored reason explains it generically', function () {
    [$user, $workspace, $channel] = publishQueueSetup();
    $failed = Post::factory()->failed()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'scheduled_at' => now()->subDay(),
    ]);
    PostPlatform::factory()->failed()->create([
        'post_id' => $failed->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
        'error_message' => null,
        'error_context' => null,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForPublishQueueTestId($page, "post-status-{$failed->id}");
    $page->click("@post-status-{$failed->id}");
    waitForPublishQueueTestId($page, "post-failure-{$failed->id}");

    $page->assertSeeIn("@post-failure-reason-{$failed->id}", __('posts.publish.failure.generic'))
        ->assertMissing("@post-failure-details-{$failed->id}")
        ->assertNoJavaScriptErrors();
});
