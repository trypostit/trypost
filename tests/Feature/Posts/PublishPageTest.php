<?php

declare(strict_types=1);

use App\Actions\Post\BuildPublishPageProps;
use App\Enums\Post\Origin;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\User\WeekStart;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

    $this->user = User::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->channel = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'UTC',
        'posting_goal' => 5,
        'posting_schedule' => publishPageSchedule(),
    ]);
});

function publishPageSchedule(): PostingSchedule
{
    $schedule = PostingSchedule::empty();

    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, '12:00');
    }

    return $schedule;
}

function publishPagePost(SocialAccount $channel, PostStatus $status, array $attributes = [], array $platform = []): Post
{
    $post = Post::factory()->create(array_merge([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $channel->workspace->user_id,
        'status' => $status,
    ], $attributes));

    PostPlatform::factory()->create(array_merge([
        'post_id' => $post->id,
        'social_account_id' => $channel->id,
        'platform' => $channel->platform,
    ], $platform));

    return $post;
}

function publishPageQueryCount(object $test, string $tab, int $postsPerStatus): int
{
    foreach ($test->queryCountChannels as $channel) {
        foreach (range(1, $postsPerStatus) as $index) {
            publishPagePost($channel, PostStatus::Scheduled, [
                'schedule_mode' => ScheduleMode::Custom,
                'scheduled_at' => now()->addDays(2)->addMinutes($index + ($test->queryCountBatch * 100)),
            ]);
            publishPagePost($channel, PostStatus::Draft);
            publishPagePost($channel, PostStatus::PendingApproval, [
                'schedule_mode' => ScheduleMode::Custom,
                'scheduled_at' => now()->addDays(3)->addMinutes($index + ($test->queryCountBatch * 100)),
                'approval_requested_at' => now(),
            ]);
            publishPagePost($channel, PostStatus::Published, ['published_at' => now()->subDay()], [
                'status' => PlatformStatus::Published,
                'published_at' => now()->subDay(),
                'platform_post_id' => "urn:li:share:{$test->queryCountBatch}{$index}",
            ]);
        }
    }

    $test->queryCountBatch++;

    DB::flushQueryLog();
    DB::enableQueryLog();

    $test->actingAs($test->user)->get(route('app.posts.index', ['tab' => $tab]))->assertOk();

    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('the all-channels page defaults to the queue in the user time zone with channel slots', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('publish/Index')
            ->where('scope', 'all')
            ->where('channel', null)
            ->where('tab', 'queue')
            ->where('displayTimezone', 'America/Sao_Paulo')
            ->missing('channelTimezones')
            ->where('channels.0.timezone', 'UTC')
            ->where('queue.queueDays', 14)
            ->where('queue.days.0.items.0.type', 'slot')
            ->where('queue.days.0.items.0.channel_id', $this->channel->id)
            ->where('queue.days.0.items.0.at', '2026-10-05T12:00:00+00:00')
            ->has('posts.data', 0)
            ->has('timezones')
            ->has('filterAccounts', 1));
});

test('the channel page renders the channel scope with its goal and weekly count', function () {
    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $this->channel))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('publish/Index')
            ->where('scope', 'channel')
            ->where('channel.id', $this->channel->id)
            ->where('channel.posting_goal', 5)
            ->where('channel.sent_this_week', 0)
            ->where('channel.has_posting_schedule', true)
            ->where('queue.days.0.items.0.channel_id', $this->channel->id));
});

test('the drafts tab returns only drafts and no queue', function () {
    $undated = publishPagePost($this->channel, PostStatus::Draft, ['scheduled_at' => null]);
    $dated = publishPagePost($this->channel, PostStatus::Draft, ['scheduled_at' => now()->addDay()]);
    publishPagePost($this->channel, PostStatus::Scheduled, ['scheduled_at' => now()->addDay()]);
    publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subDay()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'drafts']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('publish/Index')
            ->where('tab', 'drafts')
            ->missing('queue')
            ->has('posts.data', 2)
            ->where('posts.data.0.id', $undated->id)
            ->where('posts.data.1.id', $dated->id)
            ->where('posts.data.0.can_delete', true)
            ->where('posts.data.0.post_platforms.0.social_account.has_posting_schedule', true));
});

test('the sent tab returns settled posts newest first with metrics', function () {
    $older = publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subDays(3)], [
        'status' => PlatformStatus::Published,
        'published_at' => now()->subDays(3),
    ]);
    $partial = publishPagePost($this->channel, PostStatus::PartiallyPublished, ['published_at' => now()->subDay()], [
        'status' => PlatformStatus::Published,
        'published_at' => now()->subDay(),
    ]);
    $failed = publishPagePost($this->channel, PostStatus::Failed, ['published_at' => null, 'scheduled_at' => now()->subDays(5)], ['status' => PlatformStatus::Failed]);
    publishPagePost($this->channel, PostStatus::Draft);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'sent']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'sent')
            ->missing('queue')
            ->has('posts.data', 3)
            ->where('posts.data.0.id', $partial->id)
            ->where('posts.data.1.id', $older->id)
            ->where('posts.data.2.id', $failed->id)
            ->where('posts.data.0.can_delete', false)
            ->where('posts.data.2.can_delete', true)
            ->has("posts.data.0.metrics.{$partial->postPlatforms->first()->id}")
            ->where("posts.data.2.metrics.{$failed->postPlatforms->first()->id}.reason", 'not_published'));
});

test('the sent tab places a failed post at the time it was attempted, not when its row was last touched', function () {
    $older = publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
    $newer = publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subDay(), 'updated_at' => now()]);
    $failed = publishPagePost($this->channel, PostStatus::Failed, ['published_at' => null, 'scheduled_at' => now()->subDays(2), 'updated_at' => now()]);
    $unscheduledFailed = publishPagePost($this->channel, PostStatus::Failed, ['published_at' => null, 'scheduled_at' => null, 'created_at' => now()->subDays(21), 'updated_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'sent']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.data.0.id', $newer->id)
            ->where('posts.data.1.id', $failed->id)
            ->where('posts.data.2.id', $older->id)
            ->where('posts.data.3.id', $unscheduledFailed->id));
});

test('the sent tab lists publishing, failed and partially published posts with the published ones', function () {
    $published = publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subDays(4)]);
    $partial = publishPagePost($this->channel, PostStatus::PartiallyPublished, ['published_at' => now()->subDays(3)]);
    $failed = publishPagePost($this->channel, PostStatus::Failed, ['scheduled_at' => now()->subDays(2)]);
    $publishing = publishPagePost($this->channel, PostStatus::Publishing, ['scheduled_at' => now()->subMinute()]);
    publishPagePost($this->channel, PostStatus::Scheduled, ['scheduled_at' => now()->addDay()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'sent']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.sent', 4)
            ->where('counts.queue', 1)
            ->where('posts.data', fn ($posts) => collect($posts)->pluck('id')->all() === [$publishing->id, $failed->id, $partial->id, $published->id]));

    $this->get(route('app.channels.publish', [$this->channel, 'tab' => 'sent']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.sent', 4)
            ->has('posts.data', 4));
});

test('a focused failed or publishing post opens the sent tab', function (PostStatus $status) {
    $post = publishPagePost($this->channel, $status, ['scheduled_at' => now()->subDay()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['post' => $post->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'sent')
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $post->id));
})->with([
    'failed' => [PostStatus::Failed],
    'publishing' => [PostStatus::Publishing],
]);

test('a partial reload resolves only the props it asks for', function () {
    DB::enableQueryLog();

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertInertia(function (AssertableInertia $page) {
            DB::flushQueryLog();

            $page->reloadOnly(['queue', 'counts'], fn (AssertableInertia $reload) => $reload
                ->has('queue')
                ->has('counts')
                ->missing('labels')
                ->missing('filterAccounts')
                ->missing('timezones')
                ->missing('signatures'));
        });

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect($queries->filter(fn (string $query): bool => str_contains($query, 'workspace_labels')))->toBeEmpty()
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'signatures')))->toBeEmpty();
});

test('tab values resolve to a known tab', function (string $requested, string $expected) {
    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => $requested]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', $expected));
})->with([
    'queue' => ['queue', 'queue'],
    'drafts' => ['drafts', 'drafts'],
    'sent' => ['sent', 'sent'],
    'old draft value' => ['draft', 'queue'],
    'unknown' => ['everything', 'queue'],
]);

test('counts follow the page scope', function () {
    $other = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    publishPagePost($this->channel, PostStatus::Scheduled, ['scheduled_at' => now()->addDay()]);
    publishPagePost($this->channel, PostStatus::Draft);
    publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subDay()]);
    publishPagePost($other, PostStatus::Draft);
    publishPagePost($other, PostStatus::Failed);
    publishPagePost($other, PostStatus::PartiallyPublished, ['published_at' => now()->subDay()]);
    Post::factory()->create(['status' => PostStatus::Draft]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts', ['queue' => 1, 'drafts' => 2, 'sent' => 3, 'approvals' => 0]));

    $this->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts', ['queue' => 1, 'drafts' => 1, 'sent' => 1, 'approvals' => 0]));

    $this->get(route('app.posts.index', ['channels' => [$other->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts', ['queue' => 0, 'drafts' => 1, 'sent' => 2, 'approvals' => 0])
            ->where('filters.channels', [$other->id]));
});

test('the queue no longer carries failed or publishing posts', function () {
    publishPagePost($this->channel, PostStatus::Failed, ['scheduled_at' => now()->subDay()]);
    publishPagePost($this->channel, PostStatus::Publishing, ['scheduled_at' => now()->subMinute()]);
    publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subDay()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.queue', 0)
            ->missing('queue.needsAttention')
            ->has('posts.data', 0));

    $this->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('queue.needsAttention')
            ->has('posts.data', 0));
});

test('scheduled posts in the queue are sent as paginated cards', function () {
    $post = publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $post->id)
            ->where('posts.data.0.can_delete', true)
            ->where('posts.data.0.post_platforms.0.social_account.has_posting_schedule', true));
});

test('an invalid time zone and an oversized queue window fall back safely', function () {
    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tz' => 'Not/AZone', 'queue_days' => 999]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('displayTimezone', 'America/Sao_Paulo')
            ->where('queue.queueDays', 90)
            ->where('queue.maxQueueDays', BuildPublishPageProps::MAX_QUEUE_DAYS));

    $this->get(route('app.posts.index', ['tz' => 'Asia/Tokyo', 'queue_days' => 3]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('displayTimezone', 'Asia/Tokyo')
            ->where('queue.queueDays', 14));
});

test("sent this week starts on the viewer's week start in the channel time zone", function (WeekStart $weekStart, int $expected) {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'America/Sao_Paulo'));
    $this->channel->update(['timezone' => 'America/Sao_Paulo']);
    $this->user->update(['week_starts_on' => $weekStart]);

    publishPagePost($this->channel, PostStatus::Published, [], [
        'status' => PlatformStatus::Published,
        'published_at' => CarbonImmutable::parse('2026-09-28 00:30:00', 'America/Sao_Paulo')->utc(),
    ]);
    publishPagePost($this->channel, PostStatus::Published, [], [
        'status' => PlatformStatus::Published,
        'published_at' => CarbonImmutable::parse('2026-09-27 23:30:00', 'America/Sao_Paulo')->utc(),
    ]);
    publishPagePost($this->channel, PostStatus::Published, [], [
        'status' => PlatformStatus::Published,
        'enabled' => false,
        'published_at' => CarbonImmutable::parse('2026-09-29 10:00:00', 'America/Sao_Paulo')->utc(),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.sent_this_week', $expected));
})->with([
    'monday' => [WeekStart::Monday, 1],
    'sunday' => [WeekStart::Sunday, 2],
]);

test('scheduled this week counts the posts still to go out before the week ends in the channel time zone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'America/Sao_Paulo'));
    $this->channel->update(['timezone' => 'America/Sao_Paulo']);
    $this->user->update(['week_starts_on' => WeekStart::Monday]);

    publishPagePost($this->channel, PostStatus::Scheduled, [
        'scheduled_at' => CarbonImmutable::parse('2026-10-02 09:00:00', 'America/Sao_Paulo')->utc(),
    ]);
    publishPagePost($this->channel, PostStatus::Scheduled, [
        'scheduled_at' => CarbonImmutable::parse('2026-10-04 23:30:00', 'America/Sao_Paulo')->utc(),
    ]);
    publishPagePost($this->channel, PostStatus::Scheduled, [
        'scheduled_at' => CarbonImmutable::parse('2026-10-05 00:30:00', 'America/Sao_Paulo')->utc(),
    ]);
    publishPagePost($this->channel, PostStatus::Scheduled, [
        'scheduled_at' => CarbonImmutable::parse('2026-10-03 10:00:00', 'America/Sao_Paulo')->utc(),
    ], ['enabled' => false]);
    publishPagePost($this->channel, PostStatus::Draft);

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.scheduled_this_week', 2));
});

test('the publish page of another workspace channel is not found', function () {
    $foreign = SocialAccount::factory()->linkedin()->create();

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $foreign))
        ->assertNotFound();
});

test('compose on the channel page reopens it with the composer', function () {
    $this->actingAs($this->user)
        ->get(route('app.channels.publish', [$this->channel, 'compose' => 1, 'tab' => 'drafts']))
        ->assertRedirect(route('app.channels.publish', [$this->channel, 'tab' => 'drafts']))
        ->assertSessionHas('flash.openPostComposer.assistant', false);
});

test('the page runs no n+1 queries as posts grow', function (string $tab) {
    $this->queryCountBatch = 0;
    $this->queryCountChannels = collect([
        $this->channel,
        SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'posting_schedule' => publishPageSchedule()]),
        SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]),
    ]);

    $this->actingAs($this->user)->get(route('app.posts.index', ['tab' => $tab]))->assertOk();

    $small = publishPageQueryCount($this, $tab, 1);
    $large = publishPageQueryCount($this, $tab, 4);

    expect($large)->toBe($small);
})->with(['queue', 'drafts', 'sent', 'approvals']);

test('the label filter narrows lists, queue cards and needs attention', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $tagged = publishPagePost($this->channel, PostStatus::Draft);
    $tagged->labels()->attach($label);
    publishPagePost($this->channel, PostStatus::Draft);
    $taggedFailed = publishPagePost($this->channel, PostStatus::Failed);
    $taggedFailed->labels()->attach($label);
    publishPagePost($this->channel, PostStatus::Failed);
    $taggedQueued = publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDay(),
    ]);
    $taggedQueued->labels()->attach($label);
    publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays(2),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'drafts', 'labels' => [$label->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $tagged->id)
            ->where('filters.labels', [$label->id])
            ->where('counts', ['queue' => 2, 'drafts' => 2, 'sent' => 2, 'approvals' => 0]));

    $this->get(route('app.posts.index', ['labels' => [$label->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $taggedQueued->id)
            ->missing('queue.needsAttention'));

    $this->get(route('app.posts.index', ['tab' => 'sent', 'labels' => [$label->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $taggedFailed->id));
});

test('untagged narrows to posts without labels and unions with selected labels', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $other = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $tagged = publishPagePost($this->channel, PostStatus::Draft);
    $tagged->labels()->attach($label);
    $otherTagged = publishPagePost($this->channel, PostStatus::Draft);
    $otherTagged->labels()->attach($other);
    $untagged = publishPagePost($this->channel, PostStatus::Draft);
    $untaggedQueued = publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDay(),
    ]);
    $taggedQueued = publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays(2),
    ]);
    $taggedQueued->labels()->attach($label);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'drafts', 'untagged' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.untagged', true)
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $untagged->id));

    $this->get(route('app.posts.index', ['tab' => 'drafts', 'untagged' => 1, 'labels' => [$label->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.data', fn ($posts) => collect($posts)->pluck('id')->sort()->values()->all()
                === collect([$tagged->id, $untagged->id])->sort()->values()->all()));

    $this->get(route('app.posts.index', ['untagged' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $untaggedQueued->id));

    $this->get(route('app.posts.index', ['tab' => 'drafts']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.untagged', false)
            ->has('posts.data', 3));
});

test('a channel filter from another workspace yields no posts and zero counts', function () {
    $foreign = SocialAccount::factory()->linkedin()->create(['posting_schedule' => publishPageSchedule()]);
    publishPagePost($this->channel, PostStatus::Draft);
    publishPagePost($this->channel, PostStatus::Failed);
    publishPagePost($this->channel, PostStatus::Scheduled, ['scheduled_at' => now()->addDay()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['channels' => [$foreign->id]]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts', ['queue' => 0, 'drafts' => 0, 'sent' => 0, 'approvals' => 0])
            ->where('queue.days', [])
            ->has('posts.data', 0)
            ->missing('queue.needsAttention'));

    $this->get(route('app.posts.index', ['tab' => 'sent', 'channels' => [$foreign->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 0));

    $this->get(route('app.posts.index', ['tab' => 'drafts', 'channels' => [$foreign->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 0));
});

test('drafts with the same schedule are ordered newest created first', function () {
    $older = publishPagePost($this->channel, PostStatus::Draft, ['scheduled_at' => null, 'created_at' => now()->subHour()]);
    $newer = publishPagePost($this->channel, PostStatus::Draft, ['scheduled_at' => null, 'created_at' => now()]);
    $datedOlder = publishPagePost($this->channel, PostStatus::Draft, ['scheduled_at' => now()->addDay(), 'created_at' => now()->subHour()]);
    $datedNewer = publishPagePost($this->channel, PostStatus::Draft, ['scheduled_at' => now()->addDay(), 'created_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => 'drafts']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.data.0.id', $newer->id)
            ->where('posts.data.1.id', $older->id)
            ->where('posts.data.2.id', $datedNewer->id)
            ->where('posts.data.3.id', $datedOlder->id));
});

test('malformed ids in the query string are ignored', function () {
    publishPagePost($this->channel, PostStatus::Draft);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['channels' => ['foo'], 'labels' => ['bar'], 'notes' => 'foo']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.channels', [])
            ->where('filters.labels', [])
            ->where('openPostNotesId', null)
            ->where('counts.drafts', 1));

    $this->get(route('app.posts.index', ['tab' => 'drafts', 'notes' => 'foo']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('posts.data', 1));
});

test('an empty tab still follows the notes deep link', function () {
    $post = publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subDay()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['tab' => '', 'notes' => $post->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tab', 'sent')
            ->has('posts.data', 1));
});

test('a queue with scheduled posts lists every one of them and no slots', function () {
    $other = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'posting_schedule' => publishPageSchedule()]);
    $tomorrow = publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'),
    ]);
    $later = publishPagePost($other, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays(20),
    ]);
    $farAway = publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays(120),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queue.days', [])
            ->where('posts.data', fn ($posts) => collect($posts)->pluck('id')->all() === [$tomorrow->id, $later->id, $farAway->id])
            ->where('posts.data.1.schedule_mode', ScheduleMode::Custom->value)
            ->where('counts.queue', 3)
            ->where('channels', fn ($channels) => collect($channels)->sum('scheduled_posts_count') === 3));

    $this->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queue.days', fn ($days) => collect($days)->flatMap(fn (array $day): array => $day['items'])->isNotEmpty())
            ->where('posts.data', fn ($posts) => collect($posts)->pluck('id')->all() === [$tomorrow->id, $farAway->id])
            ->where('counts.queue', 2)
            ->where('channels', fn ($channels) => collect($channels)->firstWhere('id', $this->channel->id)['scheduled_posts_count'] === 2));
});

test('a channel queue always interleaves its free posting times with its scheduled posts', function () {
    $queued = publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'),
    ]);
    $custom = publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => CarbonImmutable::parse('2026-10-07 12:00:00', 'UTC'),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.data', fn ($posts) => collect($posts)->pluck('id')->all() === [$queued->id, $custom->id])
            ->where('queue.days.0.items', [
                ['type' => 'slot', 'at' => '2026-10-05T12:00:00+00:00', 'channel_id' => $this->channel->id, 'post_id' => null],
            ])
            ->where('queue.days.1.items', [
                ['type' => 'post', 'at' => '2026-10-06T12:00:00+00:00', 'channel_id' => $this->channel->id, 'post_id' => $queued->id],
            ])
            ->where('queue.days.2.items', [
                ['type' => 'post', 'at' => '2026-10-07T12:00:00+00:00', 'channel_id' => $this->channel->id, 'post_id' => $custom->id],
            ])
            ->where('queue.days.3.items.0.type', 'slot'));
});

test('the scheduled queue paginates with the default page size', function () {
    config()->set('app.pagination.default', 2);

    $posts = collect(range(1, 3))->map(fn (int $day): Post => publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays($day * 30),
    ]));

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queue.days', [])
            ->where('posts.data', fn ($data) => collect($data)->pluck('id')->all() === [$posts[0]->id, $posts[1]->id]));

    $this->get(route('app.posts.index', ['page' => 2]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.data', fn ($data) => collect($data)->pluck('id')->all() === [$posts[2]->id]));
});

test('an empty queue shows the posting slots only inside the window', function () {
    publishPagePost($this->channel, PostStatus::Draft, ['scheduled_at' => now()->addDay()]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 0)
            ->where('counts.queue', 0)
            ->where('queue.queueDays', BuildPublishPageProps::MIN_QUEUE_DAYS)
            ->where('queue.days', function ($days) {
                $items = collect($days)->flatMap(fn (array $day): array => $day['items']);

                return $items->isNotEmpty()
                    && $items->every(fn (array $item): bool => $item['type'] === 'slot'
                        && CarbonImmutable::parse($item['at'])->lessThanOrEqualTo(now()->addDays(BuildPublishPageProps::MIN_QUEUE_DAYS)));
            }));

    $this->get(route('app.posts.index', ['queue_days' => 28]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queue.queueDays', 28)
            ->where('queue.days', fn ($days) => collect($days)->flatMap(fn (array $day): array => $day['items'])
                ->contains(fn (array $item): bool => CarbonImmutable::parse($item['at'])->greaterThan(now()->addDays(20)))));
});

test('the channel filter decides between posts and slots per selection', function () {
    $other = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'posting_schedule' => publishPageSchedule()]);
    $scheduled = publishPagePost($other, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays(40),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['channels' => [$other->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('queue.days', [])
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $scheduled->id)
            ->where('counts.queue', 1));

    $this->get(route('app.posts.index', ['channels' => [$this->channel->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 0)
            ->where('counts.queue', 0)
            ->where('queue.days', fn ($days) => collect($days)->flatMap(fn (array $day): array => $day['items'])
                ->every(fn (array $item): bool => $item['type'] === 'slot' && $item['channel_id'] === $this->channel->id)));
});

test('a label filter without matching scheduled posts shows neither posts nor slots', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    publishPagePost($this->channel, PostStatus::Scheduled, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays(3),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.posts.index', ['labels' => [$label->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.data', 0)
            ->where('queue.days', []));
});

test('the sent tab lists imported posts by publish time and counts them', function () {
    $tryPost = publishPagePost($this->channel, PostStatus::Published, ['published_at' => now()->subHours(2)], [
        'status' => PlatformStatus::Published,
        'published_at' => now()->subHours(2),
    ]);
    $imported = publishPagePost($this->channel, PostStatus::Published, [
        'origin' => Origin::Network,
        'user_id' => null,
        'published_at' => now()->subHour(),
    ], [
        'status' => PlatformStatus::Published,
        'published_at' => now()->subHour(),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', [$this->channel, 'tab' => 'sent']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.sent', 2)
            ->where('posts.data.0.id', $imported->id)
            ->where('posts.data.0.origin', Origin::Network->value)
            ->where('posts.data.0.can_delete', false)
            ->where('posts.data.1.id', $tryPost->id)
            ->where('posts.data.1.origin', Origin::TryPost->value));
});

test('imported posts count toward the weekly posting goal', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'UTC'));

    publishPagePost($this->channel, PostStatus::Published, ['origin' => Origin::Network, 'user_id' => null], [
        'status' => PlatformStatus::Published,
        'published_at' => CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC'),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('channel.sent_this_week', 1));
});

test('the queue carries the pending queue requests holding a slot, and the slot is no free posting time', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $slot = CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC');

    $holder = publishPagePost($this->channel, PostStatus::PendingApproval, [
        'user_id' => $requester->id,
        'approval_requested_by' => $requester->id,
        'approval_requested_at' => now(),
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => $slot,
    ]);
    publishPagePost($this->channel, PostStatus::PendingApproval, [
        'user_id' => $requester->id,
        'approval_requested_by' => $requester->id,
        'approval_requested_at' => now(),
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => null,
    ]);
    publishPagePost($this->channel, PostStatus::PendingApproval, [
        'user_id' => $requester->id,
        'approval_requested_by' => $requester->id,
        'approval_requested_at' => now(),
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => $slot->addHours(3),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('counts.queue', 0)
            ->where('counts.approvals', 3)
            ->has('queue.pending', 1)
            ->where('queue.pending.0.id', $holder->id)
            ->where('queue.pending.0.status', PostStatus::PendingApproval->value)
            ->where('queue.pending.0.scheduled_at', fn ($value) => CarbonImmutable::parse($value)->equalTo($slot))
            ->where('queue.pending.0.post_platforms.0.social_account_id', $this->channel->id)
            ->where('queue.days', fn ($days) => collect($days)->flatMap(fn ($day) => $day['items'])
                ->where('type', 'slot')
                ->doesntContain('at', $slot->toIso8601String())));

    $this->get(route('app.posts.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('queue.pending', 1)
            ->where('queue.pending.0.id', $holder->id));
});

test('a requester sees only their own pending slot holders in the queue', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $colleague = workspaceMember($this->workspace, 'approval');

    $pending = fn (User $user, string $at): Post => publishPagePost($this->channel, PostStatus::PendingApproval, [
        'user_id' => $user->id,
        'approval_requested_by' => $user->id,
        'approval_requested_at' => now(),
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => CarbonImmutable::parse($at, 'UTC'),
    ]);

    $own = $pending($requester, '2026-10-06 12:00:00');
    $colleagues = $pending($colleague, '2026-10-07 12:00:00');

    $this->actingAs($requester)
        ->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('queue.pending', 1)
            ->where('queue.pending.0.id', $own->id)
            ->where('queue.days', fn ($days) => collect($days)->flatMap(fn ($day) => $day['items'])
                ->where('type', 'slot')
                ->doesntContain('at', '2026-10-07T12:00:00+00:00')));

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', $this->channel))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('queue.pending', 2)
            ->where('queue.pending.0.id', $own->id)
            ->where('queue.pending.1.id', $colleagues->id));
});

test('pending slot holders follow the label filter', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $holder = publishPagePost($this->channel, PostStatus::PendingApproval, [
        'user_id' => $requester->id,
        'approval_requested_by' => $requester->id,
        'approval_requested_at' => now(),
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => CarbonImmutable::parse('2026-10-06 12:00:00', 'UTC'),
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.publish', [$this->channel, 'labels' => [$label->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('queue.pending', 0));

    $holder->labels()->attach($label->id);

    $this->get(route('app.channels.publish', [$this->channel, 'labels' => [$label->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('queue.pending', 1)
            ->where('queue.pending.0.id', $holder->id));
});
