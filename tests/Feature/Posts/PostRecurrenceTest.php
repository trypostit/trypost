<?php

declare(strict_types=1);

use App\Actions\Post\FinalizePostPublication;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->user = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
    ]);
});

function recurringPost(SocialAccount $channel, User $user, array $attributes = []): Post
{
    $post = Post::factory()->create(array_merge([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'content' => 'A recurring caption',
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => now()->addDays(2),
    ], $attributes));

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $channel->id,
        'meta' => ['visibility' => 'PUBLIC'],
    ]);

    return $post->refresh();
}

function publishRecurringPost(Post $post, PostPlatformStatus $status = PostPlatformStatus::Published): void
{
    $post->update(['status' => PostStatus::Publishing]);
    $post->postPlatforms()->update(['status' => $status]);

    app(FinalizePostPublication::class)->handle($post);
}

function nextOccurrence(Post $post): ?Post
{
    return Post::query()
        ->where('workspace_id', $post->workspace_id)
        ->whereKeyNot($post->id)
        ->with('postPlatforms', 'labels')
        ->first();
}

test('a scheduled post can be made recurring', function () {
    $post = recurringPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->from(route('app.posts.index'))
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 2, 'frequency' => 'week', 'times' => 3])
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('flash.banner')
        ->assertRedirect(route('app.posts.index'));

    $post->refresh();

    expect($post->recurrence_interval)->toBe(2)
        ->and($post->recurrence_frequency)->toBe(RecurrenceFrequency::Week)
        ->and($post->recurrence_remaining)->toBe(3)
        ->and($post->isRecurring())->toBeTrue();
});

test('a recurrence can be changed and stopped', function () {
    $post = recurringPost($this->channel, $this->user, [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 4,
    ]);

    $this->actingAs($this->user)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'month', 'times' => 6])
        ->assertSessionHasNoErrors();

    expect($post->refresh()->recurrence_frequency)->toBe(RecurrenceFrequency::Month)
        ->and($post->recurrence_remaining)->toBe(6);

    $this->actingAs($this->user)
        ->delete(route('app.posts.recurrence.destroy', $post))
        ->assertSessionHasNoErrors()
        ->assertSessionMissing('flash.banner');

    expect($post->refresh()->isRecurring())->toBeFalse()
        ->and($post->recurrence_remaining)->toBeNull();
});

test('the recurrence rule is validated', function (array $payload, string $field) {
    $post = recurringPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->patch(route('app.posts.recurrence.update', $post), $payload)
        ->assertSessionHasErrors($field);

    expect($post->refresh()->isRecurring())->toBeFalse();
})->with([
    'zero interval' => [['interval' => 0, 'frequency' => 'week', 'times' => 1], 'interval'],
    'interval above the max' => [['interval' => 366, 'frequency' => 'day', 'times' => 1], 'interval'],
    'unknown frequency' => [['interval' => 1, 'frequency' => 'hour', 'times' => 1], 'frequency'],
    'zero times' => [['interval' => 1, 'frequency' => 'week', 'times' => 0], 'times'],
    'times above the max' => [['interval' => 1, 'frequency' => 'day', 'times' => 101], 'times'],
]);

test('only scheduled posts can recur', function (PostStatus $status, bool $withTime) {
    $post = recurringPost($this->channel, $this->user, [
        'status' => $status,
        'scheduled_at' => $withTime ? now()->addDay() : null,
    ]);

    $this->actingAs($this->user)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'week', 'times' => 2])
        ->assertSessionHasErrors('post');

    expect($post->refresh()->isRecurring())->toBeFalse();
})->with([
    'draft' => [PostStatus::Draft, false],
    'draft with a time' => [PostStatus::Draft, true],
    'published' => [PostStatus::Published, true],
    'failed' => [PostStatus::Failed, true],
    'publishing' => [PostStatus::Publishing, true],
]);

test('the last occurrence must stay before 2038', function () {
    $post = recurringPost($this->channel, $this->user, ['scheduled_at' => CarbonImmutable::parse('2037-06-01 09:00', 'UTC')]);

    $this->actingAs($this->user)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'year', 'times' => 1])
        ->assertSessionHasErrors('times');

    $this->actingAs($this->user)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'month', 'times' => 6])
        ->assertSessionHasNoErrors();

    expect($post->refresh()->recurrence_remaining)->toBe(6);
});

test('a member who needs approval cannot change a recurrence', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $post = recurringPost($this->channel, $this->user, [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 2,
    ]);

    $this->actingAs($requester)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'day', 'times' => 2])
        ->assertForbidden();

    expect($post->refresh()->recurrence_frequency)->toBe(RecurrenceFrequency::Week)
        ->and($post->recurrence_remaining)->toBe(2);
});

test('a member who needs approval can stop a recurrence', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $post = recurringPost($this->channel, $this->user, [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 2,
    ]);

    $this->actingAs($requester)
        ->delete(route('app.posts.recurrence.destroy', $post))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($post->refresh()->isRecurring())->toBeFalse()
        ->and($post->recurrence_remaining)->toBeNull();
});

test('a user outside the workspace cannot change or stop a recurrence', function () {
    $outsider = workspaceOutsider($this->workspace);
    $post = recurringPost($this->channel, $this->user, [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 2,
    ]);

    $this->actingAs($outsider)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'day', 'times' => 2])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->delete(route('app.posts.recurrence.destroy', $post))
        ->assertForbidden();

    expect($post->refresh()->recurrence_frequency)->toBe(RecurrenceFrequency::Week);
});

test('a post of another workspace is not found', function () {
    $foreignChannel = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $post = recurringPost($foreignChannel, User::factory()->create());

    $this->actingAs($this->user)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'day', 'times' => 2])
        ->assertNotFound();

    $this->actingAs($this->user)
        ->delete(route('app.posts.recurrence.destroy', $post))
        ->assertNotFound();
});

test('publishing a recurring post schedules exactly one next occurrence', function (RecurrenceFrequency $frequency, string $scheduledAt, string $expected) {
    $this->travelTo(CarbonImmutable::parse($scheduledAt, 'UTC')->addMinutes(5));
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => CarbonImmutable::parse($scheduledAt, 'UTC'),
        'recurrence_interval' => 1,
        'recurrence_frequency' => $frequency,
        'recurrence_remaining' => 3,
        'post_group_id' => '0190a3b2-0000-7000-8000-000000000001',
    ]);
    $post->labels()->attach($label);

    publishRecurringPost($post);

    $published = $post->refresh();
    $next = nextOccurrence($post);

    expect(Post::count())->toBe(2)
        ->and($published->status)->toBe(PostStatus::Published)
        ->and($published->isRecurring())->toBeFalse()
        ->and($next->status)->toBe(PostStatus::Scheduled)
        ->and($next->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($next->scheduled_at->toDateTimeString())->toBe($expected)
        ->and($next->content)->toBe('A recurring caption')
        ->and($next->recurrence_frequency)->toBe($frequency)
        ->and($next->recurrence_interval)->toBe(1)
        ->and($next->recurrence_remaining)->toBe(2)
        ->and($next->post_group_id)->not->toBeNull()
        ->and($next->post_group_id)->not->toBe($post->post_group_id)
        ->and($next->postPlatforms)->toHaveCount(1)
        ->and($next->postPlatforms->first()->social_account_id)->toBe($this->channel->id)
        ->and($next->postPlatforms->first()->meta)->toEqual(['visibility' => 'PUBLIC'])
        ->and($next->labels->pluck('id')->all())->toBe([$label->id]);
})->with([
    'day' => [RecurrenceFrequency::Day, '2026-10-05 09:00', '2026-10-06 09:00:00'],
    'week' => [RecurrenceFrequency::Week, '2026-10-05 09:00', '2026-10-12 09:00:00'],
    'month' => [RecurrenceFrequency::Month, '2026-10-05 09:00', '2026-11-05 09:00:00'],
    'month end does not overflow' => [RecurrenceFrequency::Month, '2027-01-31 09:00', '2027-02-28 09:00:00'],
    'year' => [RecurrenceFrequency::Year, '2026-10-05 09:00', '2027-10-05 09:00:00'],
    'leap day year' => [RecurrenceFrequency::Year, '2028-02-29 09:00', '2029-02-28 09:00:00'],
]);

test('the interval multiplies the step', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:05', 'UTC'));
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => CarbonImmutable::parse('2026-10-05 09:00', 'UTC'),
        'recurrence_interval' => 2,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 2,
    ]);

    publishRecurringPost($post);

    expect(nextOccurrence($post)->scheduled_at->toDateTimeString())->toBe('2026-10-19 09:00:00');
});

test('the series follows the author time zone across daylight saving changes', function () {
    $this->user->update(['timezone' => 'America/New_York']);
    $this->travelTo(CarbonImmutable::parse('2026-10-30 13:05', 'UTC'));
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => CarbonImmutable::parse('2026-10-30 09:00', 'America/New_York')->utc(),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 2,
    ]);

    publishRecurringPost($post);

    expect(nextOccurrence($post)->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-11-06 09:00');
});

test('the last occurrence carries no rule and ends the series', function () {
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => now()->subMinute(),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Day,
        'recurrence_remaining' => 1,
    ]);

    publishRecurringPost($post);

    $last = nextOccurrence($post);

    expect($last->isRecurring())->toBeFalse()
        ->and($last->recurrence_remaining)->toBeNull();

    $this->travelTo($last->scheduled_at->addMinute());
    publishRecurringPost($last);

    expect(Post::count())->toBe(2);
});

test('a retried settlement never creates a second occurrence', function () {
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => now()->subMinute(),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 5,
    ]);

    publishRecurringPost($post);
    app(FinalizePostPublication::class)->handle($post->refresh());
    app(FinalizePostPublication::class)->handle($post->refresh());

    expect(Post::count())->toBe(2);
});

test('a failed post hands its recurrence to the next occurrence', function () {
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => now()->subMinute(),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 5,
    ]);

    publishRecurringPost($post, PostPlatformStatus::Failed);

    $next = nextOccurrence($post);

    expect($post->refresh()->status)->toBe(PostStatus::Failed)
        ->and($post->isRecurring())->toBeFalse()
        ->and(Post::count())->toBe(2)
        ->and($next->status)->toBe(PostStatus::Scheduled)
        ->and($next->scheduled_at->toDateTimeString())->toBe(now()->subMinute()->addWeek()->toDateTimeString())
        ->and($next->recurrence_remaining)->toBe(4);
});

test('retrying a failed occurrence never creates a second next occurrence', function () {
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => now()->subMinute(),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 5,
    ]);

    publishRecurringPost($post, PostPlatformStatus::Failed);
    publishRecurringPost($post->refresh());

    expect($post->refresh()->status)->toBe(PostStatus::Published)
        ->and(Post::count())->toBe(2);
});

test('a failure while scheduling the next occurrence keeps the rule for a later settlement', function () {
    Exceptions::fake();
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => now()->subMinute(),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 5,
    ]);
    $failing = true;
    Post::creating(function () use (&$failing): void {
        if ($failing) {
            throw new RuntimeException('Storage unavailable');
        }
    });

    publishRecurringPost($post);

    Exceptions::assertReported(RuntimeException::class);
    expect($post->refresh()->status)->toBe(PostStatus::Published)
        ->and($post->isRecurring())->toBeTrue()
        ->and($post->recurrence_remaining)->toBe(5)
        ->and(Post::count())->toBe(1);

    $failing = false;
    publishRecurringPost($post);

    expect($post->refresh()->isRecurring())->toBeFalse()
        ->and(Post::count())->toBe(2)
        ->and(nextOccurrence($post)->recurrence_remaining)->toBe(4);
});

test('a multi-channel post continues the series on every enabled channel', function () {
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $disabled = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => now()->subMinute(),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Day,
        'recurrence_remaining' => 2,
    ]);
    PostPlatform::factory()->failed()->create(['post_id' => $post->id, 'social_account_id' => $other->id]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $disabled->id, 'enabled' => false]);
    $post->update(['status' => PostStatus::Publishing]);
    $post->postPlatforms()->where('social_account_id', $this->channel->id)->update(['status' => PostPlatformStatus::Published]);

    app(FinalizePostPublication::class)->handle($post);

    $occurrences = Post::query()->whereKeyNot($post->id)->with('postPlatforms')->get();

    expect($post->refresh()->status)->toBe(PostStatus::PartiallyPublished)
        ->and($post->isRecurring())->toBeFalse()
        ->and($occurrences)->toHaveCount(2)
        ->and($occurrences->map(fn (Post $occurrence): string => $occurrence->postPlatforms->sole()->social_account_id)->sort()->values()->all())
        ->toBe(collect([$this->channel->id, $other->id])->sort()->values()->all())
        ->and($occurrences->every(fn (Post $occurrence): bool => $occurrence->recurrence_remaining === 1
            && $occurrence->scheduled_at->equalTo(now()->subMinute()->addDay())))->toBeTrue();
});

test('an anchor in the past catches up and consumes the skipped occurrences', function () {
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => now()->subDays(17),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 5,
    ]);

    publishRecurringPost($post);

    $next = nextOccurrence($post);

    expect($next->scheduled_at->toDateTimeString())->toBe(now()->subDays(17)->addWeeks(3)->toDateTimeString())
        ->and($next->recurrence_remaining)->toBe(2);
});

test('a catch-up past the remaining occurrences ends the series', function () {
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => now()->subDays(30),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 2,
    ]);

    publishRecurringPost($post);

    expect(Post::count())->toBe(1)
        ->and($post->refresh()->isRecurring())->toBeFalse();
});

test('publishing now keeps the rhythm of the original time', function () {
    $scheduledAt = now()->addDays(3)->startOfMinute();
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => $scheduledAt,
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 2,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'publish_now'])
        ->assertSessionHasNoErrors();

    expect($post->refresh()->status)->toBe(PostStatus::Publishing)
        ->and($post->recurrence_anchor_at->equalTo($scheduledAt))->toBeTrue();

    publishRecurringPost($post);

    expect(nextOccurrence($post)->scheduled_at->toDateTimeString())->toBe($scheduledAt->addWeek()->toDateTimeString());
});

test('publishing now from any entry point keeps the rhythm of the original time', function (Closure $publishNow) {
    $scheduledAt = now()->addDays(3)->startOfMinute();
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => $scheduledAt,
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 2,
    ]);

    $publishNow($this, $post);

    expect($post->refresh()->status)->toBe(PostStatus::Publishing)
        ->and($post->recurrence_anchor_at?->equalTo($scheduledAt))->toBeTrue();

    publishRecurringPost($post);

    expect(nextOccurrence($post)->scheduled_at->toDateTimeString())->toBe($scheduledAt->addWeek()->toDateTimeString());
})->with([
    'composer' => [function ($test, Post $post): void {
        $test->actingAs($test->user)
            ->put(route('app.posts.update', $post), ['status' => 'publishing', 'content' => 'Now', 'scheduled_at' => null])
            ->assertSessionHasNoErrors();
    }],
    'mcp' => [function ($test, Post $post): void {
        TryPostServer::actingAs($test->user)
            ->tool(PublishPostTool::class, ['post_id' => $post->id])
            ->assertOk();
    }],
    'api' => [function ($test, Post $post): void {
        $token = createApiTestToken(['workspace' => $test->workspace])['plain_token'];

        $test->withHeaders(['Authorization' => "Bearer {$token}"])
            ->putJson(route('api.posts.update', $post), ['status' => 'publishing', 'scheduled_at' => null])
            ->assertOk();
    }],
]);

test('a monthly series keeps the day of the month it started on', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-31 09:05', 'UTC'));
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => CarbonImmutable::parse('2027-01-31 09:00', 'UTC'),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Month,
        'recurrence_remaining' => 3,
    ]);

    $dates = [];

    foreach (range(1, 3) as $step) {
        publishRecurringPost($post);
        $post = nextOccurrence($post);
        $dates[] = $post->scheduled_at->toDateTimeString();
        $this->travelTo($post->scheduled_at->addMinutes(5));
        Post::query()->whereKeyNot($post->id)->delete();
    }

    expect($dates)->toBe(['2027-02-28 09:00:00', '2027-03-31 09:00:00', '2027-04-30 09:00:00']);
});

test('a yearly series that starts on a leap day returns to it in leap years', function () {
    $this->travelTo(CarbonImmutable::parse('2028-02-29 09:05', 'UTC'));
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => CarbonImmutable::parse('2028-02-29 09:00', 'UTC'),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Year,
        'recurrence_remaining' => 4,
    ]);

    $dates = [];

    foreach (range(1, 4) as $step) {
        publishRecurringPost($post);
        $post = nextOccurrence($post);
        $dates[] = $post->scheduled_at->toDateString();
        $this->travelTo($post->scheduled_at->addMinutes(5));
        Post::query()->whereKeyNot($post->id)->delete();
    }

    expect($dates)->toBe(['2029-02-28', '2030-02-28', '2031-02-28', '2032-02-29']);
});

test('rescheduling an occurrence off the series moves the series to the new time', function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-31 09:05', 'UTC'));
    $post = recurringPost($this->channel, $this->user, [
        'scheduled_at' => CarbonImmutable::parse('2027-01-31 09:00', 'UTC'),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Month,
        'recurrence_remaining' => 3,
    ]);

    publishRecurringPost($post);
    $second = nextOccurrence($post);
    $post->delete();

    UpdatePost::execute($this->workspace, $second, [
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => CarbonImmutable::parse('2027-03-03 10:00', 'UTC')->toIso8601String(),
    ]);
    $this->travelTo(CarbonImmutable::parse('2027-03-03 10:05', 'UTC'));
    publishRecurringPost($second->refresh());

    expect(nextOccurrence($second)->scheduled_at->toDateTimeString())->toBe('2027-04-03 10:00:00');
});

test('the 2038 ceiling is checked in the author time zone', function () {
    $this->user->update(['timezone' => 'America/New_York']);
    $post = recurringPost($this->channel, $this->user, ['scheduled_at' => CarbonImmutable::parse('2037-07-31 23:30', 'UTC')]);

    $this->actingAs($this->user)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'month', 'times' => 5])
        ->assertSessionHasErrors('times');

    $this->actingAs($this->user)
        ->patch(route('app.posts.recurrence.update', $post), ['interval' => 1, 'frequency' => 'month', 'times' => 4])
        ->assertSessionHasNoErrors();
});

test('moving a recurring post to drafts clears its recurrence', function () {
    $post = recurringPost($this->channel, $this->user, [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 3,
    ]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'draft'])
        ->assertSessionHasNoErrors();

    expect($post->refresh()->status)->toBe(PostStatus::Draft)
        ->and($post->isRecurring())->toBeFalse()
        ->and($post->recurrence_remaining)->toBeNull();
});

test('editing a recurring post or its time keeps the rule', function () {
    $post = recurringPost($this->channel, $this->user, [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 3,
    ]);

    UpdatePost::execute($this->workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'content' => 'Edited caption',
        'scheduled_at' => now()->addDays(5)->toIso8601String(),
    ]);

    expect($post->refresh()->content)->toBe('Edited caption')
        ->and($post->scheduled_at->toDateTimeString())->toBe(now()->addDays(5)->toDateTimeString())
        ->and($post->recurrence_frequency)->toBe(RecurrenceFrequency::Week)
        ->and($post->recurrence_remaining)->toBe(3);
});

test('deleting a recurring post ends the series', function () {
    $post = recurringPost($this->channel, $this->user, [
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Week,
        'recurrence_remaining' => 3,
    ]);

    $this->actingAs($this->user)
        ->delete(route('app.posts.destroy', $post))
        ->assertRedirect();

    expect(Post::count())->toBe(0);
});

test('occurrences of an approved series are scheduled without approval', function () {
    $requester = workspaceMember($this->workspace, 'approval', ['timezone' => 'UTC']);
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:05', 'UTC'));
    $post = recurringPost($this->channel, $requester, [
        'scheduled_at' => CarbonImmutable::parse('2026-10-05 09:00', 'UTC'),
        'recurrence_interval' => 1,
        'recurrence_frequency' => RecurrenceFrequency::Day,
        'recurrence_remaining' => 2,
    ]);

    publishRecurringPost($post);

    expect(nextOccurrence($post)->status)->toBe(PostStatus::Scheduled);
});
