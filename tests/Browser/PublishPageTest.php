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

test('the channel page shows the weekly goal progress', function () {
    [$user, , $channel] = publishPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishPageTestId($page, 'publish-goal-progress');

    expect($page->script('new URL(document.querySelector(\'[data-testid="schedule-view-calendar"]\').href).search'))->toBe('');
    $page->assertSeeIn('@publish-goal-progress', '0/3')
        ->assertAttribute('@publish-goal-pie', 'data-percent', '0')
        ->assertNoJavaScriptErrors();
});

test('the weekly goal pie fills with the posts sent this week', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $post = publishPagePost($user, $workspace, $channel);
    $post->postPlatforms()->update(['status' => 'published', 'published_at' => now()]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishPageTestId($page, 'publish-goal-pie');

    $page->assertSeeIn('@publish-goal-progress', '1/3')
        ->assertAttribute('@publish-goal-pie', 'data-percent', '33')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelectorAll('[data-testid=\"publish-goal-pie\"] circle').length"))->toBe(2);
});

test('clicking the weekly goal opens a popover with sent, scheduled and to do', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $sent = publishPagePost($user, $workspace, $channel);
    $sent->update(['status' => PostStatus::Published]);
    $sent->postPlatforms()->update(['status' => 'published', 'published_at' => now()]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishPageTestId($page, 'publish-goal-progress');

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

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishPageTestId($page, "post-card-{$draft->id}");

    $page->assertVisible("@post-card-{$draft->id}")
        ->assertSeeIn('@publish-tab-count-queue', '1')
        ->assertNoJavaScriptErrors();
});

test('a queued post moves to drafts from its menu', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $queued = publishPagePost($user, $workspace, $channel);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $channel));
    waitForPublishPageTestId($page, "post-card-menu-{$queued->id}");
    $page->click("@post-card-menu-{$queued->id}");
    waitForPublishPageTestId($page, "post-move-drafts-{$queued->id}");
    $page->click("@post-move-drafts-{$queued->id}");
    waitForPublishPagePostStatus($page, $queued, PostStatus::Draft);

    expect($queued->refresh()->status)->toBe(PostStatus::Draft);
    $page->assertNoJavaScriptErrors();
});

test('the sent tab shows a published post', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $published = Post::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'published_at' => now()->subHour(),
    ]);
    PostPlatform::factory()->published()->create([
        'post_id' => $published->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
        'enabled' => true,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', ['account' => $channel, 'tab' => 'sent']));
    waitForPublishPageTestId($page, "post-card-{$published->id}");

    $page->assertVisible("@post-card-{$published->id}")
        ->assertSeeIn('@publish-tab-count-sent', '1')
        ->click("@post-card-menu-{$published->id}");
    waitForPublishPageTestId($page, "post-duplicate-{$published->id}");

    $page->assertVisible("@post-duplicate-{$published->id}")
        ->assertMissing("@post-delete-{$published->id}")
        ->assertMissing("@post-move-drafts-{$published->id}")
        ->assertNoJavaScriptErrors();
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

test('the sidebar shows scheduled counts per channel and in total', function () {
    [$user, $workspace, $channel] = publishPageSetup();
    $other = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    publishPagePost($user, $workspace, $channel);
    $custom = Post::factory()->scheduled()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    PostPlatform::factory()->create([
        'post_id' => $custom->id,
        'social_account_id' => $other->id,
        'platform' => $other->platform,
        'enabled' => true,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForPublishPageTestId($page, "sidebar-channel-count-{$channel->id}");

    $page->assertSeeIn("@sidebar-channel-count-{$channel->id}", '1')
        ->assertSeeIn("@sidebar-channel-count-{$other->id}", '1')
        ->assertSeeIn('@sidebar-publish-count', '2')
        ->assertNoJavaScriptErrors();
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
})->with(['approvals', 'drafts', 'sent']);

test('an empty tab on a channel page offers a new post, not the welcome', function () {
    [$user, , $channel] = publishPageSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', ['account' => $channel, 'tab' => 'drafts']));
    waitForPublishPageTestId($page, 'publish-empty-new-post-drafts');

    $page->assertMissing('@publish-welcome')
        ->assertVisible('@publish-empty-connect-more')
        ->assertNoJavaScriptErrors();
});

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

test('the queue tab shows the empty state when there are no posts and no posting times', function () {
    [$user, , $channel] = publishPageSetup();
    $channel->update(['posting_schedule' => PostingSchedule::empty()]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'queue']));
    waitForPublishPageTestId($page, 'publish-empty-illustration');

    $page->assertSeeIn('@empty-state', __('posts.publish.empty.queue.title'))
        ->assertVisible('@publish-empty-new-post-queue')
        ->assertNoJavaScriptErrors();
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
