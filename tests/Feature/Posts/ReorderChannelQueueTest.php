<?php

declare(strict_types=1);

use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Actions\Post\Queue\ReorderChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $schedule = PostingSchedule::empty();
    foreach (range(0, 6) as $day) {
        $schedule = $schedule->withTime($day, '09:00')->withTime($day, '12:00')->withTime($day, '18:00');
    }

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'UTC',
        'posting_schedule' => $schedule,
    ]);

    $this->a = reorderQueuedPost($this->channel, $this->user);
    $this->b = reorderQueuedPost($this->channel, $this->user);
    $this->c = reorderQueuedPost($this->channel, $this->user);
});

function reorderQueuedPost(SocialAccount $channel, User $user, array $attributes = [], bool $enqueue = true): Post
{
    $post = Post::factory()->create(array_merge([
        'workspace_id' => $channel->workspace_id,
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => null,
    ], $attributes));

    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $channel->id]);

    if ($enqueue) {
        ReflowChannelQueue::handle($channel, $post, QueuePosition::Next);
    }

    return $post->refresh();
}

/**
 * @return array<string, string>
 */
function reorderTimes(Post ...$posts): array
{
    return collect($posts)
        ->mapWithKeys(fn (Post $post): array => [$post->id => $post->fresh()->scheduled_at->toIso8601String()])
        ->all();
}

test('reordering gives the posts the occupied slots in the new order', function () {
    $before = reorderTimes($this->a, $this->b, $this->c);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->c->id, $this->a->id, $this->b->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->c->fresh()->scheduled_at->toIso8601String())->toBe($before[$this->a->id])
        ->and($this->a->fresh()->scheduled_at->toIso8601String())->toBe($before[$this->b->id])
        ->and($this->b->fresh()->scheduled_at->toIso8601String())->toBe($before[$this->c->id]);
});

test('a leading prefix of the queue is reordered and the posts after it are untouched', function () {
    $d = reorderQueuedPost($this->channel, $this->user);
    $before = reorderTimes($this->a, $this->b, $this->c, $d);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->b->id, $this->a->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->b->fresh()->scheduled_at->toIso8601String())->toBe($before[$this->a->id])
        ->and($this->a->fresh()->scheduled_at->toIso8601String())->toBe($before[$this->b->id])
        ->and($this->c->fresh()->scheduled_at->toIso8601String())->toBe($before[$this->c->id])
        ->and($d->fresh()->scheduled_at->toIso8601String())->toBe($before[$d->id]);
});

test('ids that skip a queued post are not a prefix and change nothing', function () {
    $before = reorderTimes($this->a, $this->b, $this->c);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->a->id, $this->c->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['post_ids' => __('posts.errors.queue_order_stale')]);

    expect(reorderTimes($this->a, $this->b, $this->c))->toBe($before);
});

test('an id beyond the prefix range is rejected and changes nothing', function () {
    $d = reorderQueuedPost($this->channel, $this->user);
    $before = reorderTimes($this->a, $this->b, $this->c, $d);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->a->id, $d->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['post_ids' => __('posts.errors.queue_order_stale')]);

    expect(reorderTimes($this->a, $this->b, $this->c, $d))->toBe($before);
});

test('a stale id set on the inertia path returns a session error', function () {
    $before = reorderTimes($this->a, $this->b, $this->c);

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->c->id, $this->a->id]])
        ->assertRedirect()
        ->assertSessionHasErrors(['post_ids' => __('posts.errors.queue_order_stale')]);

    expect(reorderTimes($this->a, $this->b, $this->c))->toBe($before);
});

test('duplicate ids are rejected by the request and by the action', function () {
    $before = reorderTimes($this->a, $this->b, $this->c);
    $ids = [$this->a->id, $this->a->id, $this->b->id];

    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.order', $this->channel), ['post_ids' => $ids])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post_ids.0');

    expect(fn () => ReorderChannelQueue::handle($this->channel, $ids))
        ->toThrow(ValidationException::class, __('posts.errors.queue_order_stale'));

    expect(reorderTimes($this->a, $this->b, $this->c))->toBe($before);
});

test('a custom post or another channel post cannot be part of the order', function (string $intruder) {
    $post = match ($intruder) {
        'custom' => reorderQueuedPost($this->channel, $this->user, [
            'schedule_mode' => ScheduleMode::Custom,
            'scheduled_at' => now()->addDays(2),
        ], false),
        'other channel' => reorderQueuedPost(
            SocialAccount::factory()->create([
                'workspace_id' => $this->workspace->id,
                'timezone' => 'UTC',
                'posting_schedule' => $this->channel->posting_schedule,
            ]),
            $this->user,
        ),
    };
    $before = reorderTimes($this->a, $this->b, $this->c);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.order', $this->channel), ['post_ids' => [$post->id, $this->c->id, $this->a->id, $this->b->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post_ids');

    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.order', $this->channel), ['post_ids' => [$post->id, $this->a->id, $this->b->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post_ids');

    expect(reorderTimes($this->a, $this->b, $this->c))->toBe($before);
})->with(['custom', 'other channel']);

test('a queued post due within a minute is not reorderable and never moves', function () {
    $due = reorderQueuedPost($this->channel, $this->user, ['scheduled_at' => now()->addSeconds(30)], false);
    $dueAt = $due->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->c->id, $due->id, $this->a->id, $this->b->id]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('post_ids');

    $firstSlot = $this->a->fresh()->scheduled_at->toIso8601String();

    $this->actingAs($this->user)
        ->put(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->c->id, $this->a->id, $this->b->id]])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($due->fresh()->scheduled_at->toIso8601String())->toBe($dueAt)
        ->and($this->c->fresh()->scheduled_at->toIso8601String())->toBe($firstSlot);
});

test('a busy queue lock returns a conflict', function () {
    $lock = Cache::lock("queue:{$this->channel->id}", 10);
    expect($lock->get())->toBeTrue();

    $before = reorderTimes($this->a, $this->b, $this->c);

    try {
        $this->actingAs($this->user)
            ->putJson(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->c->id, $this->a->id, $this->b->id]])
            ->assertConflict()
            ->assertJson(['message' => __('posts.errors.queue_busy')]);
    } finally {
        $lock->release();
    }

    expect(reorderTimes($this->a, $this->b, $this->c))->toBe($before);
});

test('a channel of another workspace is not found', function () {
    $other = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['account_id' => $other->account_id, 'user_id' => $other->id]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.queue.order', $channel), ['post_ids' => [$this->a->id]])
        ->assertNotFound();
});

test('a member who needs approval cannot reorder the queue', function () {
    $requester = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($requester->id, membershipPivot('approval'));
    $before = reorderTimes($this->a, $this->b, $this->c);

    $this->actingAs($requester)
        ->putJson(route('app.channels.queue.order', $this->channel), ['post_ids' => [$this->c->id, $this->a->id, $this->b->id]])
        ->assertForbidden();

    expect(reorderTimes($this->a, $this->b, $this->c))->toBe($before);
});
