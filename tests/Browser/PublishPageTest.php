<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\PostingSchedule;

function waitForPublishPageTestId(mixed $page, string $testId): void
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

function waitForPublishPageScript(mixed $page, string $condition): void
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

function waitForPublishPagePostStatus(mixed $page, Post $post, PostStatus $status): void
{
    for ($attempt = 0; $attempt < 50 && $post->refresh()->status !== $status; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount}
 */
function publishPageSetup(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $time = now()->utc()->addHours(6)->format('H:00');
    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, $time);
    }

    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => $schedule,
        'posting_goal' => 3,
    ]);

    return [$user, $workspace, $channel];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function publishPagePost(User $user, Workspace $workspace, SocialAccount $channel, array $overrides = []): Post
{
    return CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Publish page post',
        'media' => [],
        'label_ids' => [],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
        ...$overrides,
    ])->first();
}

test('the weekly goal shows its progress, fills the pie and opens a popover with sent, scheduled and to do', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $sent = publishPagePost($user, $workspace, $channel);
    $sent->update(['status' => PostStatus::Published]);
    $sent->postPlatforms()->update(['status' => 'published', 'published_at' => now()]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishPageTestId($page, 'publish-goal-progress');

    expect($page->script('new URL(document.querySelector(\'[data-testid="schedule-view-calendar"]\').href).search'))->toBe('')
        ->and($page->script('new URL(document.querySelector(\'[data-testid="schedule-view-calendar"]\').href).pathname'))->toBe(route('app.channels.calendar', ['account' => $channel->id, 'view' => 'week'], false));
    $page->assertSeeIn('@publish-goal-progress', trans('posts.publish.goal', ['sent' => 1, 'goal' => 3]))
        ->assertAttribute('@publish-goal-pie', 'data-percent', '33');
    expect($page->script("document.querySelectorAll('[data-testid=\"publish-goal-pie\"] circle').length"))->toBe(2);

    $page->click('@publish-goal-progress');
    waitForPublishPageTestId($page, 'publish-goal-popover');

    $page->assertSeeIn('@publish-goal-popover', trans_choice('posts.publish.goal_popover.per_week', 3, ['count' => 3]))
        ->assertVisible('@publish-goal-edit')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('[data-testid=\"publish-goal-summary\"]').textContent.replace(/\\s+/g, ' ').trim()"))
        ->toBe('1 '.__('posts.publish.goal_popover.sent').'·0 '.__('posts.publish.goal_popover.scheduled').'·2 '.__('posts.publish.goal_popover.to_do'));
});

test('a draft is added to the queue from the drafts tab', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $draft = publishPagePost($user, $workspace, $channel, ['status' => 'draft', 'queue' => null]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishPageTestId($page, 'publish-tab-drafts');
    $page->click('@publish-tab-drafts');
    waitForPublishPageTestId($page, "post-add-to-queue-{$draft->id}");

    $page->assertVisible("@post-card-{$draft->id}")
        ->click("@post-add-to-queue-{$draft->id}");
    waitForPublishPagePostStatus($page, $draft, PostStatus::Scheduled);

    expect($draft->refresh()->status)->toBe(PostStatus::Scheduled)
        ->and($draft->schedule_mode)->toBe(ScheduleMode::Queue);

    $page->assertNoJavaScriptErrors();
});

test('add to queue is disabled for a draft whose channel has no posting times', function () {
    [$user, $workspace] = publishPageSetup();
    $channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => null,
    ]);
    $draft = publishPagePost($user, $workspace, $channel, ['status' => 'draft', 'queue' => null]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', ['account' => $channel, 'tab' => 'drafts']));
    waitForPublishPageTestId($page, "post-add-to-queue-{$draft->id}");

    $page->assertAttribute("@post-add-to-queue-{$draft->id}", 'disabled', '')
        ->assertNoJavaScriptErrors();
    expect($draft->refresh()->status)->toBe(PostStatus::Draft);
});

test('editing a draft from a channel page keeps the channel page and tab', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $draft = publishPagePost($user, $workspace, $channel, ['status' => 'draft', 'queue' => null]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', ['account' => $channel, 'tab' => 'drafts']));
    waitForPublishPageTestId($page, "post-edit-{$draft->id}");
    $page->click("@post-edit-{$draft->id}");
    waitForPublishPageTestId($page, 'post-composer-dialog');

    expect($page->script('location.pathname'))->toBe(parse_url(route('app.channels.publish', $channel), PHP_URL_PATH))
        ->and($page->script('new URLSearchParams(location.search).get("tab")'))->toBe('drafts')
        ->and($page->script('new URLSearchParams(location.search).get("edit")'))->toBe($draft->id);
    $page->assertVisible('@post-composer-dialog')->assertNoJavaScriptErrors();
});

test('saving a queued post edited on a channel page returns to that page and tab', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $queued = publishPagePost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', ['account' => $channel, 'tab' => 'queue']));
    waitForPublishPageTestId($page, "post-edit-{$queued->id}");
    $page->click("@post-edit-{$queued->id}");
    waitForPublishPageTestId($page, 'composer-submit');
    $page->click('@composer-submit');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!new URLSearchParams(location.search).has('edit') && !document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
    waitForPublishPageTestId($page, "post-card-{$queued->id}");

    expect($page->script('location.pathname'))->toBe(parse_url(route('app.channels.publish', $channel), PHP_URL_PATH))
        ->and($page->script('new URLSearchParams(location.search).get("tab")'))->toBe('queue')
        ->and($page->script('new URLSearchParams(location.search).has("edit")'))->toBeFalse();
    $page->assertVisible("@post-card-{$queued->id}")->assertNoJavaScriptErrors();
});

test('a sent card shows the metrics band in order and links to the publication insights', function () {
    [$user, $workspace] = publishPageSetup();
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Published,
        'scheduled_at' => now()->subHour(),
        'published_at' => now()->subHour(),
    ]);
    $target = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $target->id,
        'platform' => Platform::X,
        'network' => Platform::X->network(),
        'remote_id' => $target->platform_post_id,
    ]);
    $metric = fn (float $value, string $unit = 'count'): array => ['value' => $value, 'unit' => $unit, 'availability' => 'available'];
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'metrics' => [
            'reactions' => $metric(40),
            'impressions' => $metric(900),
            'engagement_rate' => $metric(5.5, 'percent'),
            'comments' => $metric(7),
            'shares' => $metric(3),
        ],
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    waitForPublishPageTestId($page, "post-metrics-{$post->id}");

    expect($page->script("[...document.querySelectorAll('[data-testid=\"post-metrics-{$post->id}\"] dd')].map((value) => value.textContent.trim())"))
        ->toBe(['40', '7', '5.5%', '900', '3'])
        ->and($page->script("new URL(document.querySelector('[data-testid=\"post-insights-{$post->id}\"]').href).pathname"))
        ->toBe(parse_url(route('app.channels.insights', $account), PHP_URL_PATH));

    $page->assertNoJavaScriptErrors();
});

test('the approvals tab groups pending posts by day with unscheduled requests last', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $timed = Post::factory()->pendingApproval()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'scheduled_at' => now()->utc()->addDays(2)->setTime(12, 0),
    ]);
    $queued = Post::factory()->pendingApproval()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'scheduled_at' => null,
        'schedule_mode' => ScheduleMode::Queue,
    ]);

    foreach ([$timed, $queued] as $post) {
        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $channel->id,
            'platform' => $channel->platform,
            'enabled' => true,
        ]);
    }

    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'approvals']));
    waitForPublishPageTestId($page, "post-card-{$queued->id}");

    $timedDay = $timed->scheduled_at->utc()->format('Y-m-d');

    expect($page->script('[...document.querySelectorAll(\'[data-testid^="publish-tab-count-"]\')].map((el) => el.dataset.testid)'))
        ->toBe(['publish-tab-count-queue', 'publish-tab-count-approvals', 'publish-tab-count-drafts', 'publish-tab-count-sent'])
        ->and($page->script('[...document.querySelectorAll(\'[data-testid^="publish-day-"]\')].map((el) => el.dataset.testid)'))
        ->toBe(["publish-day-{$timedDay}", 'publish-day-no-time']);

    $page->assertSeeIn('@publish-tab-count-approvals', '2')
        ->assertVisible("@post-card-{$timed->id}")
        ->assertSeeIn('@publish-day-no-time', 'Unscheduled')
        ->assertNoJavaScriptErrors();
});

test('an empty tab shows its illustration, copy and a new post button that opens the composer', function (string $tab) {
    [$user] = publishPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => $tab]));
    waitForPublishPageTestId($page, 'publish-empty-illustration');

    $page->assertSeeIn('@empty-state', __("posts.publish.empty.{$tab}.title"))
        ->assertSeeIn('@empty-state', __("posts.publish.empty.{$tab}.description"))
        ->assertSeeIn('@publish-empty-connect-more', __('posts.publish.welcome.connect_more'))
        ->assertMissing('@publish-welcome')
        ->click("@publish-empty-new-post-{$tab}");
    waitForPublishPageTestId($page, 'post-composer-dialog');

    $page->assertVisible('@post-composer-dialog')->assertNoJavaScriptErrors();
})->with(['drafts']);

test('a workspace without channels is welcomed with connect and invite actions', function () {
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitForPublishPageTestId($page, 'publish-welcome');

    $page->assertSeeIn('@publish-welcome', __('posts.publish.welcome.title'))
        ->assertSeeIn('@publish-welcome-invite', __('posts.publish.welcome.invite'))
        ->assertMissing('@publish-empty-new-post-drafts')
        ->click('@publish-welcome-invite');
    waitForPublishPageTestId($page, 'invite-member-dialog');

    $page->assertVisible('@invite-email')
        ->click('@invite-member-cancel');
    waitForPublishPageTestId($page, 'publish-welcome-connect');

    $page->click('@publish-welcome-connect');
    waitForPublishPageTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('an active label filter shows its count in the primary color', function () {
    [$user, $workspace] = publishPageSetup();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts', 'labels' => [$label->id]]));
    waitForPublishPageTestId($page, 'posts-label-count');

    $page->assertSeeIn('@posts-label-count', '1')
        ->assertScript(<<<'JS'
            (() => {
                const probe = document.createElement('span');
                probe.className = 'bg-primary';
                document.body.appendChild(probe);
                const primary = getComputedStyle(probe).backgroundColor;
                probe.remove();
                return getComputedStyle(document.querySelector('[data-testid="posts-label-count"]')).backgroundColor === primary;
            })()
        JS, true)
        ->assertNoJavaScriptErrors();
});

test('the tabs row border keeps the page padding instead of touching the panel edges', function () {
    [$user] = publishPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1440, 900);
    waitForPublishPageTestId($page, 'publish-filters');

    $edges = $page->script(<<<'JS'
        (() => {
            const row = document.querySelector('[data-testid="publish-filters"]').parentElement.getBoundingClientRect();
            const panel = document.querySelector('[data-slot="sidebar-inset"]').getBoundingClientRect();
            return [Math.round(row.left - panel.left), Math.round(panel.right - row.right)];
        })()
    JS);

    expect($edges[0])->toBeGreaterThanOrEqual(32)
        ->and($edges[1])->toBeGreaterThanOrEqual(32);
    $page->assertNoJavaScriptErrors();
});

test('on a phone the calendar row holds the channels, filter menu, view switch and no date last, with the filters in a sheet', function () {
    [$user, , $channel] = publishPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'week']))->resize(390, 844);
    waitForPublishPageTestId($page, 'calendar-menu');
    waitForPublishPageTestId($page, 'posts-channel-filter');

    $measure = <<<'JS'
        (() => {
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const middle = (box) => Math.round(box.top + box.height / 2);
            const row = ['posts-channel-filter', 'calendar-menu', 'schedule-view-list', 'calendar-no-date'].map(rect);
            const noDate = rect('calendar-no-date');
            const channelFilter = document.querySelector('[data-testid="posts-channel-filter"]');
            const visible = (node) => node.getClientRects().length > 0 && getComputedStyle(node).display !== 'none';

            return {
                oneRow: row.every((box) => Math.abs(middle(box) - middle(row[0])) <= 2),
                noDateLast: row.every((box) => box.right <= noDate.right),
                noDateSquare: Math.round(noDate.width) === Math.round(noDate.height),
                noDateLabelHidden: document.querySelector('[data-testid="calendar-no-date"] span').getBoundingClientRect().width <= 1,
                channelText: [...channelFilter.querySelectorAll('span')].filter((span) => visible(span) && span.textContent.trim() !== '').length,
                tags: Boolean(document.querySelector('[data-testid="posts-label-filter"]')),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        })()
    JS;

    expect($page->script($measure))->toBe([
        'oneRow' => true,
        'noDateLast' => true,
        'noDateSquare' => true,
        'noDateLabelHidden' => true,
        'channelText' => 0,
        'tags' => false,
        'overflow' => false,
    ]);

    $page->click('@calendar-menu');
    waitForPublishPageTestId($page, 'calendar-menu-content');
    waitForPublishPageScript($page, 'Math.abs(window.innerHeight - document.querySelector(\'[data-testid="calendar-menu-content"]\').getBoundingClientRect().bottom) <= 1');

    $page->assertVisible('@calendar-status-drafts')
        ->assertVisible('@publish-timezone-trigger')
        ->assertVisible('@calendar-toggle-slots');

    $page->resize(1280, 900);

    waitForPublishPageScript($page, 'Boolean(document.querySelector(\'[data-testid="calendar-status-filter"]\'))');

    $order = $page->script(<<<'JS'
        (() => ['posts-channel-filter', 'calendar-status-filter', 'posts-label-filter', 'publish-timezone-trigger', 'calendar-no-date', 'calendar-menu']
            .map((id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect().left)
            .every((left, index, lefts) => index === 0 || left > lefts[index - 1]))()
    JS);

    expect($order)->toBeTrue()
        ->and($page->script('Boolean(document.querySelector(\'[data-testid="schedule-view-list"]\').closest("header"))'))->toBeTrue()
        ->and($page->script('document.querySelector(\'[data-testid="calendar-no-date"] span\').getBoundingClientRect().width > 1'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('the channel page adapts its filters, tabs, calendar link and notes button to the viewport', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $channel->update(['display_name' => 'A channel with a rather long display name', 'posting_goal' => 5]);
    $post = publishPagePost($user, $workspace, $channel);
    $this->actingAs($user);

    $notes = <<<'JS'
        (() => {
            const row = document.querySelector('[data-testid^="post-card-"]');
            const card = row.querySelector('article').getBoundingClientRect();
            const notes = row.querySelector('[data-testid^="post-notes-trigger-"]').getBoundingClientRect();

            return {
                inside: notes.left >= card.left && notes.right <= card.right && notes.top >= card.top,
                beside: notes.left >= card.right,
            };
        })()
    JS;

    $calendarPath = 'new URL(document.querySelector(\'[data-testid="schedule-view-calendar"]\').href).pathname';

    $page = visit(route('app.channels.publish', $channel))->resize(900, 800);
    waitForPublishPageTestId($page, "post-notes-trigger-{$post->id}");
    expect($page->script($notes))->toBe(['inside' => true, 'beside' => false]);

    $page->resize(1280, 800);
    waitForPublishPageScript($page, 'document.querySelector(\'[data-testid^="post-notes-trigger-"]\').getBoundingClientRect().left >= document.querySelector(\'[data-testid^="post-card-"] article\').getBoundingClientRect().right');
    expect($page->script($notes))->toBe(['inside' => false, 'beside' => true]);

    $page->resize(390, 844);
    waitForPublishPageTestId($page, 'publish-tabs-mobile-trigger');

    $phone = $page->script(<<<'JS'
        (() => {
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const middle = (box) => Math.round(box.top + box.height / 2);
            const row = ['publish-tabs-mobile-trigger', 'publish-menu', 'schedule-view-list', 'posts-new-post'].map(rect);

            return {
                oneRow: row.every((box) => Math.abs(middle(box) - middle(row[0])) <= 2),
                belowGoal: row[0].top >= rect('publish-goal-progress').bottom,
                tags: Boolean(document.querySelector('[data-testid="posts-label-filter"]')),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        })()
    JS);

    expect($phone)->toBe(['oneRow' => true, 'belowGoal' => true, 'tags' => false, 'overflow' => false])
        ->and($page->script($calendarPath))->toBe(route('app.channels.calendar', ['account' => $channel->id, 'view' => 'days'], false))
        ->and($page->script('Boolean(document.querySelector(\'[data-testid="publish-timezone-trigger"]\'))'))->toBeFalse();

    $page->click('@publish-menu');
    waitForPublishPageTestId($page, 'publish-menu-content');
    waitForPublishPageScript($page, 'Math.abs(window.innerHeight - document.querySelector(\'[data-testid="publish-menu-content"]\').getBoundingClientRect().bottom) <= 1');

    $pressed = 'document.querySelector(\'[data-testid="publish-toggle-slots"]\').getAttribute("aria-pressed")';
    $before = $page->script($pressed);
    $page->assertVisible('@publish-timezone-trigger')
        ->click('@publish-toggle-slots');

    expect($page->script($pressed))->toBe($before === 'true' ? 'false' : 'true')
        ->and($page->script('new URL(document.querySelector(\'[data-testid="publish-manage-slots"]\').href).pathname'))
        ->toBe(route('app.channels.settings', $channel, false));

    $page->resize(1280, 800);
    waitForPublishPageScript($page, '!document.querySelector(\'[data-testid="publish-menu-content"]\')');
    waitForPublishPageTestId($page, 'publish-timezone-trigger');

    $desktop = $page->script(<<<'JS'
        (() => {
            const left = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect().left;
            const inHeader = (id) => Boolean(document.querySelector(`[data-testid="${id}"]`).closest('header'));

            return {
                switchInHeader: inHeader('schedule-view-list'),
                newPostInHeader: inHeader('posts-new-post'),
                order: left('posts-label-filter') < left('publish-timezone-trigger') && left('publish-timezone-trigger') < left('publish-menu'),
            };
        })()
    JS);

    expect($desktop)->toBe(['switchInHeader' => true, 'newPostInHeader' => true, 'order' => true]);

    $page->click('@publish-menu');
    waitForPublishPageTestId($page, 'publish-toggle-slots');
    $page->assertVisible('@publish-manage-slots');

    $page->resize(390, 844);
    waitForPublishPageTestId($page, 'publish-tabs-mobile-trigger');

    expect($page->script('document.querySelector(\'[data-testid="posts-tabs"]\').getBoundingClientRect().height'))->toBe(0);
    $page->assertSeeIn('@publish-tabs-mobile-trigger', trans('posts.publish.tabs.queue'))
        ->click('@publish-tabs-mobile-trigger');
    waitForPublishPageTestId($page, 'publish-tabs-sheet');

    $page->assertPresent('@publish-tab-sheet-queue-check')
        ->assertMissing('@publish-tab-sheet-drafts-check')
        ->click('@publish-tab-sheet-drafts');
    waitForPublishPageScript($page, 'new URLSearchParams(location.search).get("tab") === "drafts"');

    $page->assertSeeIn('@publish-tabs-mobile-trigger', trans('posts.publish.tabs.drafts'))
        ->assertMissing('@publish-tabs-sheet');

    $page->navigate(route('app.posts.index'));
    waitForPublishPageTestId($page, 'schedule-view-calendar');
    expect($page->script($calendarPath))->toBe(route('app.calendar', ['view' => 'days'], false));

    $page->resize(1280, 800);
    waitForPublishPageScript($page, $calendarPath.'.endsWith("/week")');
    expect($page->script($calendarPath))->toBe(route('app.calendar', ['view' => 'week'], false));

    $page->assertNoJavaScriptErrors();
});
