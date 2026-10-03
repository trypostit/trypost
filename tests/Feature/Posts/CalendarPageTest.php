<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
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
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00', 'UTC'));

    $this->user = User::factory()->create(['timezone' => 'America/Sao_Paulo']);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $this->x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
});

function calendarPost(SocialAccount $account, ?string $scheduledAtUtc, string $content, PostStatus $status = PostStatus::Scheduled, array $attributes = []): Post
{
    $post = Post::factory()->create([
        'workspace_id' => $account->workspace_id,
        'user_id' => $account->workspace->user_id,
        'status' => $status,
        'scheduled_at' => $scheduledAtUtc ? CarbonImmutable::parse($scheduledAtUtc, 'UTC') : null,
        'content' => $content,
        ...$attributes,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    return $post;
}

test('the calendar buckets posts into days of the user time zone by default', function () {
    calendarPost($this->linkedin, '2026-10-07 01:30:00', 'Late evening in Sao Paulo');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('posts/Calendar')
            ->where('displayTimezone', 'America/Sao_Paulo')
            ->where('currentWeekStart', '2026-10-05')
            ->has('posts.2026-10-06', 1)
            ->missing('posts.2026-10-07'));
});

test('a week that starts on Sunday moves the week boundaries', function () {
    $this->user->update(['week_starts_on' => WeekStart::Sunday]);
    calendarPost($this->linkedin, '2026-10-04 15:00:00', 'Sunday noon in Sao Paulo');
    calendarPost($this->linkedin, '2026-10-11 15:00:00', 'Next Sunday');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentWeekStart', '2026-10-04')
            ->has('posts.2026-10-04', 1)
            ->missing('posts.2026-10-11'));
});

test('a month grid that starts on Sunday reaches back to the Sunday before the first', function () {
    $this->user->update(['week_starts_on' => WeekStart::Sunday]);
    calendarPost($this->linkedin, '2026-09-27 15:00:00', 'Sunday before October');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'month']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('currentMonth', '2026-10-01')
            ->has('posts.2026-09-27', 1));

    $this->user->update(['week_starts_on' => WeekStart::Monday]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar', ['view' => 'month']))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('posts.2026-09-27'));
});

test('the tz query re-buckets the calendar and moves the range boundaries', function () {
    calendarPost($this->linkedin, '2026-10-07 01:30:00', 'Crosses midnight');
    calendarPost($this->linkedin, '2026-10-05 02:00:00', 'Sunday night in Sao Paulo');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('posts.2026-10-04')
            ->missing('posts.2026-10-05'));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'tz' => 'Asia/Tokyo']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('displayTimezone', 'Asia/Tokyo')
            ->has('posts.2026-10-05', 1)
            ->where('posts.2026-10-05.0.content', 'Sunday night in Sao Paulo')
            ->has('posts.2026-10-07', 1));
});

test('an unknown tz falls back to the user time zone', function () {
    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'month', 'tz' => 'Mars/Olympus']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('displayTimezone', 'America/Sao_Paulo')
            ->where('currentMonth', '2026-10-01'));
});

test('the day view no longer exists', function () {
    $this->actingAs($this->user)
        ->get('/schedule/calendar/day')
        ->assertNotFound();

    $this->actingAs($this->user)
        ->get("/channels/{$this->linkedin->id}/calendar/day")
        ->assertNotFound();
});

test('calendar posts carry the timeline card fields', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    calendarPost($this->linkedin, '2026-10-06 15:00:00', 'Card fields')->labels()->attach($label);
    calendarPost($this->linkedin, '2026-10-05 08:00:00', 'Sent card', PostStatus::Published, ['published_at' => CarbonImmutable::parse('2026-10-05 08:00:00', 'UTC')]);

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('posts.2026-10-06.0.content', 'Card fields')
            ->where('posts.2026-10-06.0.labels.0.id', $label->id)
            ->where('posts.2026-10-06.0.notes_count', 0)
            ->where('posts.2026-10-06.0.can_delete', true)
            ->where('posts.2026-10-06.0.user.name', $this->user->name)
            ->where('posts.2026-10-06.0.post_platforms.0.social_account.has_posting_schedule', false)
            ->missing('posts.2026-10-06.0.metrics')
            ->where('posts.2026-10-05.0.content', 'Sent card')
            ->has('posts.2026-10-05.0.metrics'));
});

test('the calendar filters by channels and ignores ids that are not uuids', function () {
    calendarPost($this->linkedin, '2026-10-06 15:00:00', 'LinkedIn post');
    calendarPost($this->x, '2026-10-06 16:00:00', 'X post');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'channels' => [$this->x->id, 'nope']]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.channels', [$this->x->id])
            ->where('scope', 'all')
            ->has('filterAccounts', 2)
            ->has('posts.2026-10-06', 1)
            ->where('posts.2026-10-06.0.content', 'X post')
            ->where('posts.2026-10-06.0.post_platforms.0.social_account_id', $this->x->id));
});

test('the calendar filters by labels', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    calendarPost($this->linkedin, '2026-10-06 15:00:00', 'Tagged')->labels()->attach($label);
    calendarPost($this->linkedin, '2026-10-06 16:00:00', 'Untagged');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'labels' => [$label->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('filters.labels', [$label->id])
            ->has('labels', 1)
            ->has('posts.2026-10-06', 1)
            ->where('posts.2026-10-06.0.content', 'Tagged'));
});

test('the channel calendar shows only that channel and its header', function () {
    calendarPost($this->linkedin, '2026-10-06 15:00:00', 'LinkedIn post');
    calendarPost($this->x, '2026-10-06 16:00:00', 'X post');

    $this->actingAs($this->user)
        ->get(route('app.channels.calendar', ['account' => $this->linkedin, 'view' => 'month', 'channels' => [$this->x->id]]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('posts/Calendar')
            ->where('scope', 'channel')
            ->where('channel.id', $this->linkedin->id)
            ->where('filters.channels', [])
            ->has('filterAccounts', 0)
            ->where('view', 'month')
            ->has('posts.2026-10-06', 1)
            ->where('posts.2026-10-06.0.content', 'LinkedIn post'));
});

test('the channel calendar of another workspace is not found', function () {
    $foreign = SocialAccount::factory()->linkedin()->create();

    $this->actingAs($this->user)
        ->get(route('app.channels.calendar', ['account' => $foreign]))
        ->assertNotFound();
});

test('the compose link keeps the calendar filters', function () {
    $this->actingAs($this->user)
        ->get(route('app.calendar', [
            'view' => 'week',
            'compose' => 1,
            'date' => '2026-10-06',
            'channels' => [$this->x->id],
            'tz' => 'UTC',
        ]))
        ->assertRedirect(route('app.calendar', ['view' => 'week', 'channels' => [$this->x->id], 'tz' => 'UTC']))
        ->assertSessionHas('flash.openPostComposer.date', '2026-10-06');
});

test('the status filter narrows the calendar to drafts, scheduled or sent posts', function () {
    calendarPost($this->linkedin, '2026-10-06 15:00:00', 'Scheduled post');
    calendarPost($this->linkedin, '2026-10-06 16:00:00', 'Dated draft', PostStatus::Draft);
    calendarPost($this->linkedin, '2026-10-05 09:00:00', 'Published post', PostStatus::Published);
    calendarPost($this->linkedin, '2026-10-05 09:30:00', 'Partial post', PostStatus::PartiallyPublished);
    calendarPost($this->linkedin, '2026-10-05 09:45:00', 'Failed post', PostStatus::Failed);

    $contents = fn (?string $status): array => $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'status' => $status]))
        ->viewData('page')['props']['posts'];

    $flatten = fn (array $days): array => collect($days)->flatten(1)->pluck('content')->sort()->values()->all();

    expect($flatten($contents(null)))->toHaveCount(5)
        ->and($flatten($contents('drafts')))->toBe(['Dated draft'])
        ->and($flatten($contents('scheduled')))->toBe(['Scheduled post'])
        ->and($flatten($contents('sent')))->toBe(['Failed post', 'Partial post', 'Published post'])
        ->and($flatten($contents('bogus')))->toHaveCount(5);

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'status' => 'sent']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.status', 'sent'));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'status' => 'bogus']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('filters.status', 'all'));
});

test('undated drafts load only when the panel is open and follow the scope and labels', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    calendarPost($this->linkedin, null, 'LinkedIn undated', PostStatus::Draft)->labels()->attach($label);
    calendarPost($this->linkedin, null, 'LinkedIn undated 2', PostStatus::Draft);
    calendarPost($this->x, null, 'X undated', PostStatus::Draft);
    calendarPost($this->linkedin, '2026-10-06 15:00:00', 'Dated draft', PostStatus::Draft);
    calendarPost($this->linkedin, '2026-10-06 16:00:00', 'Scheduled post');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'month']))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('undatedDrafts'));

    $undated = fn (string $route, array $parameters): array => collect(
        $this->actingAs($this->user)->get(route($route, [...$parameters, 'undated' => 1]))->viewData('page')['props']['undatedDrafts']['data'],
    )->pluck('content')->sort()->values()->all();

    expect($undated('app.calendar', ['view' => 'month']))->toBe(['LinkedIn undated', 'LinkedIn undated 2', 'X undated'])
        ->and($undated('app.calendar', ['view' => 'week', 'channels' => [$this->x->id]]))->toBe(['X undated'])
        ->and($undated('app.calendar', ['view' => 'week', 'labels' => [$label->id]]))->toBe(['LinkedIn undated'])
        ->and($undated('app.channels.calendar', ['account' => $this->linkedin, 'view' => 'month']))->toBe(['LinkedIn undated', 'LinkedIn undated 2']);
});

test('undated drafts are paginated with the default page size', function () {
    config()->set('app.pagination.default', 2);

    foreach (range(1, 3) as $index) {
        calendarPost($this->linkedin, null, "Undated {$index}", PostStatus::Draft);
    }

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'undated' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('undatedDrafts.data', 2));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'undated' => 1, 'undated_page' => 2]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('undatedDrafts.data', 1));
});

test('posting slots load only when enabled, cover the visible range and follow the display time zone', function () {
    $this->linkedin->update([
        'timezone' => 'America/Sao_Paulo',
        'posting_schedule' => PostingSchedule::empty()
            ->withTime(1, '06:00')
            ->withTime(1, '09:00')
            ->withTime(2, '09:00')
            ->withTime(3, '23:30'),
    ]);
    calendarPost($this->linkedin, '2026-10-06 12:00:00', 'Queued post', PostStatus::Scheduled, ['schedule_mode' => ScheduleMode::Queue]);

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertInertia(fn (AssertableInertia $page) => $page->missing('slots'));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'slots' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slots', [
                '2026-10-05' => [['at' => '2026-10-05T12:00:00+00:00', 'channel_id' => $this->linkedin->id]],
                '2026-10-07' => [['at' => '2026-10-08T02:30:00+00:00', 'channel_id' => $this->linkedin->id]],
            ]));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'slots' => 1, 'tz' => 'UTC']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('slots', 2)
            ->has('slots.2026-10-05', 1)
            ->has('slots.2026-10-08', 1));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'slots' => 1, 'week' => '2026-10-12']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('slots.2026-10-12', 2)
            ->has('slots.2026-10-13', 1)
            ->has('slots.2026-10-14', 1));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'slots' => 1, 'week' => '2026-09-28']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('slots', []));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'slots' => 1, 'channels' => [$this->x->id]]))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('slots', []));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week', 'slots' => 1, 'status' => 'sent']))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('slots', []));

    $this->actingAs($this->user)
        ->get(route('app.channels.calendar', ['account' => $this->linkedin, 'view' => 'month', 'slots' => 1]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('slots.2026-10-05', 1)
            ->missing('slots.2026-10-06'));
});

test('an imported post sits on the day it was published', function () {
    $post = Post::factory()->imported()->create([
        'workspace_id' => $this->workspace->id,
        'published_at' => CarbonImmutable::parse('2026-10-07 14:20:00', 'UTC'),
    ]);
    PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->x->id,
        'platform' => $this->x->platform,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.2026-10-07', 1)
            ->where('posts.2026-10-07.0.id', $post->id)
            ->where('posts.2026-10-07.0.calendar_at', '2026-10-07T14:20:00Z'));
});

test('a sent trypost post is placed by its publish time and a scheduled one by its schedule', function () {
    $sent = calendarPost($this->linkedin, '2026-10-06 12:00:00', 'Sent late', PostStatus::Published, [
        'published_at' => CarbonImmutable::parse('2026-10-08 09:00:00', 'UTC'),
    ]);
    $scheduled = calendarPost($this->linkedin, '2026-10-09 15:00:00', 'Planned');

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->missing('posts.2026-10-06')
            ->where('posts.2026-10-08.0.id', $sent->id)
            ->where('posts.2026-10-09.0.id', $scheduled->id)
            ->where('posts.2026-10-09.0.calendar_at', '2026-10-09T15:00:00Z'));
});

test('the calendar shows every pending request to approvers and only their own to requesters', function () {
    $requester = workspaceMember($this->workspace, 'approval', ['timezone' => 'America/Sao_Paulo']);
    $otherRequester = workspaceMember($this->workspace, 'approval', ['timezone' => 'America/Sao_Paulo']);
    $own = calendarPost($this->linkedin, '2026-10-07 15:00:00', 'My request', PostStatus::PendingApproval, ['user_id' => $requester->id]);
    $editedByMe = calendarPost($this->linkedin, '2026-10-07 16:00:00', 'Edited by me', PostStatus::PendingApproval, ['approval_requested_by' => $requester->id]);
    calendarPost($this->linkedin, '2026-10-07 17:00:00', 'Their request', PostStatus::PendingApproval, ['user_id' => $otherRequester->id]);
    $scheduled = calendarPost($this->linkedin, '2026-10-07 18:00:00', 'Scheduled');

    $this->actingAs($requester)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('posts.2026-10-07', 3)
            ->where('posts.2026-10-07.0.id', $own->id)
            ->where('posts.2026-10-07.1.id', $editedByMe->id)
            ->where('posts.2026-10-07.2.id', $scheduled->id));

    $this->actingAs($this->user)
        ->get(route('app.calendar', ['view' => 'week']))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('posts.2026-10-07', 4));
});
