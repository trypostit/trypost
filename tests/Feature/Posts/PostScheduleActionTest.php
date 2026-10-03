<?php

declare(strict_types=1);

use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'UTC'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00')->withTime(5, '09:00'),
    ]);
});

function scheduleActionPost(SocialAccount $channel, User $user, array $attributes = []): Post
{
    $post = Post::factory()->create(array_merge([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'content' => 'A caption',
        'status' => PostStatus::Draft,
        'schedule_mode' => null,
        'scheduled_at' => null,
    ], $attributes));

    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $channel->id]);

    return $post->refresh();
}

function scheduleActionQueued(SocialAccount $channel, User $user): Post
{
    $post = scheduleActionPost($channel, $user, ['status' => PostStatus::Scheduled, 'schedule_mode' => ScheduleMode::Queue]);

    ReflowChannelQueue::handle($channel, $post, QueuePosition::Next);

    return $post->refresh();
}

test('draft on a queued post unqueues it and the rest of the queue keeps its slots', function () {
    $first = scheduleActionQueued($this->channel, $this->user);
    $second = scheduleActionQueued($this->channel, $this->user);
    $freedSlot = $first->scheduled_at->toIso8601String();
    $secondSlot = $second->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $first), ['action' => 'draft'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $drafted = $first->fresh();

    expect($drafted->status)->toBe(PostStatus::Draft)
        ->and($drafted->schedule_mode)->toBeNull()
        ->and($drafted->scheduled_at?->toIso8601String())->toBe($freedSlot)
        ->and($second->fresh()->scheduled_at->toIso8601String())->toBe($secondSlot);
});

test('queue next on a draft adds it to the queue', function () {
    $draft = scheduleActionPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'queue_next'])
        ->assertSessionHasNoErrors()
        ->assertRedirect()
        ->assertSessionMissing('flash.banner');

    $draft->refresh();

    expect($draft->status)->toBe(PostStatus::Scheduled)
        ->and($draft->schedule_mode)->toBe(ScheduleMode::Queue)
        ->and($draft->scheduled_at->toIso8601String())->toBe(CarbonImmutable::parse('2026-10-05 09:00', 'UTC')->toIso8601String());
});

test('queue next on a channel without posting times is rejected and the draft stays a draft', function () {
    $this->channel->update(['posting_schedule' => null]);
    $draft = scheduleActionPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.schedule.update', $draft), ['action' => 'queue_next'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['queue' => __('posts.errors.queue_requires_schedule')]);

    $draft->refresh();

    expect($draft->status)->toBe(PostStatus::Draft)
        ->and($draft->schedule_mode)->toBeNull()
        ->and($draft->scheduled_at)->toBeNull();
});

test('queue next without posting times on the inertia path returns a session error', function () {
    $this->channel->update(['posting_schedule' => null]);
    $draft = scheduleActionPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'queue_next'])
        ->assertRedirect()
        ->assertSessionHasErrors(['queue' => __('posts.errors.queue_requires_schedule')]);

    expect($draft->fresh()->status)->toBe(PostStatus::Draft);
});

test('queue top moves the last queued post to the front', function () {
    $first = scheduleActionQueued($this->channel, $this->user);
    $second = scheduleActionQueued($this->channel, $this->user);
    $last = scheduleActionQueued($this->channel, $this->user);
    $firstSlot = $first->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $last), ['action' => 'queue_top'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($last->fresh()->scheduled_at->toIso8601String())->toBe($firstSlot)
        ->and($first->fresh()->scheduled_at->isAfter($last->fresh()->scheduled_at))->toBeTrue()
        ->and($second->fresh()->scheduled_at->isAfter($first->fresh()->scheduled_at))->toBeTrue();
});

test('publish now dispatches the publish job', function () {
    $draft = scheduleActionPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $draft), ['action' => 'publish_now'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($draft->fresh()->status)->toBe(PostStatus::Publishing);
    Queue::assertPushed(PublishPost::class, fn (PublishPost $job): bool => $job->post->is($draft));
});

test('publish now on a post without enabled platforms is rejected', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'A caption',
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->disabled()->create(['post_id' => $post->id, 'social_account_id' => $this->channel->id]);

    $this->actingAs($this->user)
        ->put(route('app.posts.schedule.update', $post), ['action' => 'publish_now'])
        ->assertRedirect()
        ->assertSessionHasErrors('platforms');

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
    Queue::assertNotPushed(PublishPost::class);
});

test('a user outside the workspace cannot change a post schedule', function () {
    $outsider = workspaceOutsider($this->workspace);
    $draft = scheduleActionPost($this->channel, $this->user);

    $this->actingAs($outsider)
        ->putJson(route('app.posts.schedule.update', $draft), ['action' => 'queue_next'])
        ->assertForbidden();

    expect($draft->fresh()->status)->toBe(PostStatus::Draft);
});

test('an unknown action is rejected', function () {
    $draft = scheduleActionPost($this->channel, $this->user);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.schedule.update', $draft), ['action' => 'archive'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('action');
});

test('a post of another workspace is not found', function () {
    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $otherChannel = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id, 'platform' => Platform::LinkedIn]);
    $post = scheduleActionPost($otherChannel, $other);

    $this->actingAs($this->user)
        ->putJson(route('app.posts.schedule.update', $post), ['action' => 'draft'])
        ->assertNotFound();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});
