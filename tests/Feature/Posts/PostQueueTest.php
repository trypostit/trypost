<?php

declare(strict_types=1);

use App\Actions\Post\CreateChannelPost;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'America/Sao_Paulo'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'America/Sao_Paulo',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00')->withTime(5, '09:00'),
    ]);
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function postQueuePayload(array $channels, array $overrides = []): array
{
    return [
        'status' => 'scheduled',
        'queue' => 'next',
        'content' => 'Queued caption',
        'media' => [],
        'destinations' => array_map(fn (SocialAccount $channel): array => [
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ], $channels),
        ...$overrides,
    ];
}

function postQueueStore(object $test, SocialAccount $channel, string $position = 'next'): Post
{
    $test->actingAs($test->user)
        ->post(route('app.posts.store'), postQueuePayload([$channel], ['queue' => $position]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index'));

    return Post::findOrFail(session('created_post_ids')[0]);
}

function postQueueSlot(Post $post, string $timezone = 'America/Sao_Paulo'): string
{
    return $post->refresh()->scheduled_at->setTimezone($timezone)->format('D H:i');
}

test('storing with queue next gives each channel its own first slot', function () {
    $other = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
        'posting_schedule' => PostingSchedule::empty()->withTime(2, '10:00'),
    ]);

    $this->actingAs($this->user)
        ->post(route('app.posts.store'), postQueuePayload([$this->channel, $other]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index'));

    $posts = Post::query()->with('postPlatforms')->whereIn('id', session('created_post_ids'))->get()
        ->keyBy(fn (Post $post): string => $post->postPlatforms->first()->social_account_id);

    expect($posts)->toHaveCount(2)
        ->and($posts[$this->channel->id]->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($posts[$this->channel->id]->status)->toBe(PostStatus::Scheduled)
        ->and(postQueueSlot($posts[$this->channel->id]))->toBe('Mon 09:00')
        ->and($posts[$other->id]->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(postQueueSlot($posts[$other->id], 'UTC'))->toBe('Tue 10:00');
});

test('storing back to back on one channel takes distinct ordered slots', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);

    expect(postQueueSlot($first))->toBe('Mon 09:00')
        ->and(postQueueSlot($second))->toBe('Wed 09:00');
});

test('storing with queue top takes the first slot and shifts the others', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);
    $top = postQueueStore($this, $this->channel, 'top');

    expect(postQueueSlot($top))->toBe('Mon 09:00')
        ->and(postQueueSlot($first))->toBe('Wed 09:00')
        ->and(postQueueSlot($second))->toBe('Fri 09:00');
});

test('storing with queue and scheduled_at is rejected', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.store'), postQueuePayload([$this->channel], [
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['queue']);

    expect(Post::count())->toBe(0);
});

test('storing with queue on a draft is rejected', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.posts.store'), postQueuePayload([$this->channel], ['status' => 'draft']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['queue']);
});

test('storing with queue for a channel without slots is rejected on that destination', function () {
    $empty = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'posting_schedule' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.posts.store'), postQueuePayload([$empty]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destinations.0.social_account_id']);

    expect(Post::count())->toBe(0);
});

test('storing with scheduled_at and no queue makes a custom post', function () {
    $this->actingAs($this->user)
        ->post(route('app.posts.store'), postQueuePayload([$this->channel], [
            'queue' => null,
            'scheduled_at' => now()->addDays(2)->toIso8601String(),
        ]))
        ->assertSessionHasNoErrors();

    $post = Post::sole();

    expect($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at->equalTo(now()->addDays(2)->startOfSecond()))->toBeTrue();
});

test('storing a draft leaves the schedule mode empty', function () {
    $this->actingAs($this->user)
        ->post(route('app.posts.store'), postQueuePayload([$this->channel], ['status' => 'draft', 'queue' => null]))
        ->assertSessionHasNoErrors();

    expect(Post::sole()->schedule_mode)->toBeNull();
});

test('updating only the content of a queued post keeps every slot', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $first), ['status' => 'scheduled', 'content' => 'Edited caption'])
        ->assertSessionHasNoErrors();

    $first->refresh();

    expect($first->content)->toBe('Edited caption')
        ->and($first->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(postQueueSlot($first))->toBe('Mon 09:00')
        ->and(postQueueSlot($second))->toBe('Wed 09:00');
});

test('giving a queued post a custom time pins it and the others keep their slots', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);
    $custom = CarbonImmutable::parse('2026-10-10 15:30', 'America/Sao_Paulo');

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $first), [
            'status' => 'scheduled',
            'content' => 'Pinned',
            'scheduled_at' => $custom->toIso8601String(),
        ])
        ->assertSessionHasNoErrors();

    $first->refresh();

    expect($first->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($first->scheduled_at->equalTo($custom))->toBeTrue()
        ->and(postQueueSlot($second))->toBe('Wed 09:00');
});

test('moving a queued post to draft clears its mode and leaves its slot free', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $first), ['status' => 'draft', 'content' => 'Back to draft'])
        ->assertSessionHasNoErrors();

    $first->refresh();

    expect($first->status)->toBe(PostStatus::Draft)
        ->and($first->schedule_mode)->toBeNull()
        ->and(postQueueSlot($second))->toBe('Wed 09:00');
});

test('publishing a queued post now leaves the others in their slots', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $first), ['status' => 'publishing', 'content' => 'Now'])
        ->assertSessionHasNoErrors();

    expect($first->refresh()->schedule_mode)->toBeNull()
        ->and(postQueueSlot($second))->toBe('Wed 09:00');
    Queue::assertPushed(PublishPost::class);
});

test('adding a draft to the queue puts it at the end', function () {
    $first = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->post(route('app.posts.store'), postQueuePayload([$this->channel], ['status' => 'draft', 'queue' => null]))
        ->assertSessionHasNoErrors();
    $draft = Post::findOrFail(session('created_post_ids')[0]);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $draft), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Joining'])
        ->assertSessionHasNoErrors();

    $draft->refresh();

    expect($draft->status)->toBe(PostStatus::Scheduled)
        ->and($draft->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(postQueueSlot($draft))->toBe('Wed 09:00')
        ->and(postQueueSlot($first))->toBe('Mon 09:00');
});

test('updating with queue is rejected when the channel has no slots', function () {
    $this->channel->update(['posting_schedule' => null]);
    $this->actingAs($this->user)
        ->post(route('app.posts.store'), postQueuePayload([$this->channel], ['status' => 'draft', 'queue' => null]));
    $draft = Post::sole();

    $this->actingAs($this->user)
        ->putJson(route('app.posts.update', $draft), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Joining'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['queue']);

    expect($draft->refresh()->status)->toBe(PostStatus::Draft);
});

test('updating with queue and scheduled_at is rejected', function () {
    $post = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.update', $post), [
            'status' => 'scheduled',
            'queue' => 'next',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['queue']);
});

test('queueing a legacy multi-target post is rejected', function () {
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $this->channel->id, 'enabled' => true]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $other->id, 'enabled' => true]);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.update', $post), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Legacy'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['queue' => __('posts.errors.queue_legacy_post')]);

    expect($post->refresh()->status)->toBe(PostStatus::Draft)
        ->and($post->schedule_mode)->toBeNull();
});

test('deleting a queued post leaves its slot free and the others in place', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);
    $third = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)->delete(route('app.posts.destroy', $first))->assertRedirect();

    expect(Post::find($first->id))->toBeNull()
        ->and(postQueueSlot($second))->toBe('Wed 09:00')
        ->and(postQueueSlot($third))->toBe('Fri 09:00');

    $next = postQueueStore($this, $this->channel);

    expect(postQueueSlot($next))->toBe('Mon 09:00');
});

test('queue top shifts only the run of queued posts up to the first gap', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);
    $third = postQueueStore($this, $this->channel);
    $fourth = postQueueStore($this, $this->channel);
    $this->actingAs($this->user)->delete(route('app.posts.destroy', $third))->assertRedirect();

    $top = postQueueStore($this, $this->channel, 'top');

    expect(postQueueSlot($top))->toBe('Mon 09:00')
        ->and(postQueueSlot($first))->toBe('Wed 09:00')
        ->and(postQueueSlot($second))->toBe('Fri 09:00')
        ->and($fourth->refresh()->scheduled_at->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i'))->toBe('2026-10-12 09:00');
});

test('changing the posting schedule reassigns the queued posts', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'America/Sao_Paulo',
            'posting_goal' => 2,
            'posting_schedule' => PostingSchedule::empty()->withTime(2, '11:00')->withTime(4, '11:00')->toArray(),
        ])
        ->assertOk();

    expect(postQueueSlot($first))->toBe('Tue 11:00')
        ->and(postQueueSlot($second))->toBe('Thu 11:00');
});

test('a schedule change keeps posts whose slot still exists and re-places only the others', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'America/Sao_Paulo',
            'posting_goal' => 2,
            'posting_schedule' => PostingSchedule::empty()->withTime(3, '09:00')->withTime(4, '11:00')->toArray(),
        ])
        ->assertOk();

    expect(postQueueSlot($second))->toBe('Wed 09:00')
        ->and(postQueueSlot($first))->toBe('Thu 11:00')
        ->and($first->schedule_mode)->toBe(ScheduleMode::Queue);
});

test('a time zone change keeps the local clock time of every queued post', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);
    $third = postQueueStore($this, $this->channel);
    $this->actingAs($this->user)->delete(route('app.posts.destroy', $second))->assertRedirect();

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'America/New_York',
            'posting_goal' => 3,
            'posting_schedule' => $this->channel->posting_schedule->toArray(),
        ])
        ->assertOk();

    expect($first->refresh()->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-10-05 09:00')
        ->and($third->refresh()->scheduled_at->setTimezone('America/New_York')->format('Y-m-d H:i'))->toBe('2026-10-09 09:00');
});

test('a time zone change keeps each local clock time even when the old instant is another slot', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 08:00', 'America/Sao_Paulo'));
    $this->channel->update(['posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(1, '12:00')->withTime(3, '09:00')->withTime(3, '12:00')]);

    $posts = [postQueueStore($this, $this->channel), postQueueStore($this, $this->channel), postQueueStore($this, $this->channel)];

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'UTC',
            'posting_goal' => 4,
            'posting_schedule' => $this->channel->posting_schedule->toArray(),
        ])
        ->assertOk();

    expect(array_map(fn (Post $post): string => postQueueSlot($post, 'UTC'), $posts))->toBe(['Mon 09:00', 'Mon 12:00', 'Wed 09:00']);
});

test('a time zone change keeps the local clock time of hourly slots', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 08:00', 'America/Sao_Paulo'));
    $schedule = PostingSchedule::empty();

    foreach (['09:00', '10:00', '11:00', '12:00'] as $time) {
        $schedule = $schedule->withTime(1, $time);
    }

    $this->channel->update(['posting_schedule' => $schedule]);
    $posts = array_map(fn (): Post => postQueueStore($this, $this->channel), range(1, 4));

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'UTC',
            'posting_goal' => 4,
            'posting_schedule' => $schedule->toArray(),
        ])
        ->assertOk();

    expect(array_map(fn (Post $post): string => postQueueSlot($post, 'UTC'), $posts))->toBe(['Mon 09:00', 'Mon 10:00', 'Mon 11:00', 'Mon 12:00']);
});

test('a time zone change keeps the local clock time of a pending approval slot holder', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 08:00', 'America/Sao_Paulo'));
    $requester = workspaceMember($this->workspace, 'approval');

    postQueueStoreAtSlot($this, $requester, $this->channel, CarbonImmutable::parse('2026-10-05 09:00', 'America/Sao_Paulo'))
        ->assertSessionHasNoErrors();
    $holder = Post::query()->sole();
    $queued = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'UTC',
            'posting_goal' => 3,
            'posting_schedule' => $this->channel->posting_schedule->toArray(),
        ])
        ->assertOk();

    expect($holder->refresh()->status)->toBe(PostStatus::PendingApproval)
        ->and($holder->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(postQueueSlot($holder, 'UTC'))->toBe('Mon 09:00')
        ->and(postQueueSlot($queued, 'UTC'))->toBe('Wed 09:00');
});

test('a time zone change moves a pending holder to the first free slot when its local slot is taken', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-04 08:00', 'America/Sao_Paulo'));
    $requester = workspaceMember($this->workspace, 'approval');

    postQueueStoreAtSlot($this, $requester, $this->channel, CarbonImmutable::parse('2026-10-05 09:00', 'America/Sao_Paulo'))
        ->assertSessionHasNoErrors();
    $holder = Post::query()->sole();
    $custom = Post::factory()->scheduled()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'scheduled_at' => CarbonImmutable::parse('2026-10-05 09:00', 'UTC'), 'schedule_mode' => ScheduleMode::Custom]);
    PostPlatform::factory()->create(['post_id' => $custom->id, 'social_account_id' => $this->channel->id, 'enabled' => true]);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'UTC',
            'posting_goal' => 3,
            'posting_schedule' => $this->channel->posting_schedule->toArray(),
        ])
        ->assertOk();

    expect(postQueueSlot($holder, 'UTC'))->toBe('Wed 09:00');
});

function postQueueStoreAtSlot(object $test, User $user, SocialAccount $channel, CarbonImmutable $slot): TestResponse
{
    $at = $slot->utc()->toIso8601String();

    return $test->actingAs($user)
        ->post(route('app.posts.store'), postQueuePayload([$channel], ['queue' => null, 'scheduled_at' => $at, 'queue_slot' => $at]));
}

test('storing at a free posting slot places the post in that slot as a queue post', function () {
    postQueueStoreAtSlot($this, $this->user, $this->channel, CarbonImmutable::parse('2026-10-07 09:00', 'America/Sao_Paulo'))
        ->assertSessionHasNoErrors();

    $post = Post::findOrFail(session('created_post_ids')[0]);
    $next = postQueueStore($this, $this->channel);

    expect(postQueueSlot($post))->toBe('Wed 09:00')
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(postQueueSlot($next))->toBe('Mon 09:00');
});

test('storing at a posting slot taken meanwhile fails without double booking', function () {
    postQueueStore($this, $this->channel);

    postQueueStoreAtSlot($this, $this->user, $this->channel, CarbonImmutable::parse('2026-10-05 09:00', 'America/Sao_Paulo'))
        ->assertSessionHasErrors(['queue_slot' => __('posts.errors.queue_order_stale')]);

    expect(Post::query()->count())->toBe(1);
});

test('storing at an instant that is not a posting slot fails', function () {
    postQueueStoreAtSlot($this, $this->user, $this->channel, CarbonImmutable::parse('2026-10-06 09:00', 'America/Sao_Paulo'))
        ->assertSessionHasErrors('queue_slot');

    expect(Post::query()->count())->toBe(0);
});

test('a member who needs approval storing at a posting slot reserves it while pending', function () {
    $requester = workspaceMember($this->workspace, 'approval');

    postQueueStoreAtSlot($this, $requester, $this->channel, CarbonImmutable::parse('2026-10-05 09:00', 'America/Sao_Paulo'))
        ->assertSessionHasNoErrors();

    $request = Post::query()->sole();
    $next = postQueueStore($this, $this->channel);

    expect($request->status)->toBe(PostStatus::PendingApproval)
        ->and($request->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(postQueueSlot($request))->toBe('Mon 09:00')
        ->and(postQueueSlot($next))->toBe('Wed 09:00');
});

test('clearing the posting schedule turns queued posts custom at their times', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.posting-schedule.update', $this->channel), [
            'timezone' => 'America/Sao_Paulo',
            'posting_goal' => 2,
            'posting_schedule' => null,
        ])
        ->assertOk();

    expect($first->refresh()->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and(postQueueSlot($first))->toBe('Mon 09:00')
        ->and($second->refresh()->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and(postQueueSlot($second))->toBe('Wed 09:00');
});

test('generating a posting schedule reflows the queue', function () {
    $post = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.generate', $this->channel), ['mode' => 'goal', 'goal' => 3])
        ->assertOk();

    $channel = $this->channel->refresh();
    $expected = $channel->posting_schedule->nextSlots(now()->addMinute(), $channel->timezone, 1)[0];

    expect($post->refresh()->scheduled_at->equalTo($expected))->toBeTrue()
        ->and($post->schedule_mode)->toBe(ScheduleMode::Queue);
});

test('copying a posting schedule reflows the queue', function () {
    $post = postQueueStore($this, $this->channel);
    $source = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'posting_schedule' => PostingSchedule::empty()->withTime(6, '07:15'),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.channels.posting-schedule.copy', $this->channel), ['from' => $source->id])
        ->assertOk();

    expect(postQueueSlot($post))->toBe('Sat 07:15');
});

test('a due queued post is published and a failed one is never reflowed', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);
    $firstSlot = $first->refresh()->scheduled_at;

    $this->travelTo($firstSlot->addMinute());
    $this->artisan('posts:process-scheduled')->assertSuccessful();

    Queue::assertPushed(PublishPost::class, fn (PublishPost $job): bool => $job->post->is($first));
    expect($first->refresh()->status)->toBe(PostStatus::Publishing);

    $first->update(['status' => PostStatus::Failed]);
    ReflowChannelQueue::handle($this->channel);

    expect($first->refresh()->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($first->scheduled_at->equalTo($firstSlot))->toBeTrue()
        ->and(postQueueSlot($second))->toBe('Wed 09:00');
});

test('re-sending queue next on a queued post keeps its slot', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $first), ['status' => 'scheduled', 'queue' => 'next', 'content' => 'Edited'])
        ->assertSessionHasNoErrors();

    expect($first->refresh()->content)->toBe('Edited')
        ->and($first->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(postQueueSlot($first))->toBe('Mon 09:00')
        ->and(postQueueSlot($second))->toBe('Wed 09:00');
});

test('queue top on a queued post moves it to the top', function () {
    $first = postQueueStore($this, $this->channel);
    $second = postQueueStore($this, $this->channel);
    $third = postQueueStore($this, $this->channel);

    $this->actingAs($this->user)
        ->put(route('app.posts.update', $second), ['status' => 'scheduled', 'queue' => 'top', 'content' => 'Urgent'])
        ->assertSessionHasNoErrors();

    expect(postQueueSlot($second))->toBe('Mon 09:00')
        ->and(postQueueSlot($first))->toBe('Wed 09:00')
        ->and(postQueueSlot($third))->toBe('Fri 09:00');
});

test('recovering an empty legacy draft into the queue gives it a slot', function () {
    $existing = postQueueStore($this, $this->channel);
    $legacy = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $this->actingAs($this->user)
        ->post(route('app.posts.store'), postQueuePayload([$this->channel], ['recover_post_id' => $legacy->id]))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index'));

    $recovered = Post::findOrFail(session('created_post_ids')[0]);

    expect(Post::find($legacy->id))->toBeNull()
        ->and($recovered->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and(postQueueSlot($recovered))->toBe('Wed 09:00')
        ->and(postQueueSlot($existing))->toBe('Mon 09:00');
});

test('enqueueing rolls back when the channel has no slot left', function () {
    DB::table('social_accounts')->where('id', $this->channel->id)->update(['posting_schedule' => null]);

    expect(fn () => ReflowChannelQueue::withLock([$this->channel->id], fn () => DB::transaction(
        fn () => CreateChannelPost::execute($this->workspace, $this->user, [
            'social_account_id' => $this->channel->id,
            'content' => 'Lost slot',
            'media' => [],
            'status' => PostStatus::Scheduled->value,
            'queue' => QueuePosition::Next,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
            'label_ids' => [],
        ], new MediaCopyBatch),
    )))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('queue');
    });

    expect(Post::count())->toBe(0);
});
