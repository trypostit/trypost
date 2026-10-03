<?php

declare(strict_types=1);

use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Exceptions\Post\QueueBusyException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 08:00', 'America/Sao_Paulo'));

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'timezone' => 'America/Sao_Paulo',
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00')->withTime(5, '09:00'),
    ]);
});

function makeQueuePost(SocialAccount $channel, array $attributes = []): Post
{
    $post = Post::factory()->create(array_merge([
        'workspace_id' => $channel->workspace_id,
        'status' => PostStatus::Scheduled,
        'schedule_mode' => ScheduleMode::Queue,
        'scheduled_at' => null,
    ], $attributes));

    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $channel->id]);

    return $post;
}

function localSlot(Post $post): string
{
    return $post->refresh()->scheduled_at->setTimezone('America/Sao_Paulo')->format('D H:i');
}

test('posts inserted at the end take the next slots in insertion order', function () {
    $first = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $first);
    $second = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $second);

    expect(localSlot($first))->toBe('Mon 09:00')
        ->and(localSlot($second))->toBe('Wed 09:00');
});

test('a post inserted at the top pushes the others down', function () {
    $first = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $first);
    $second = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $second);
    $top = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $top, QueuePosition::Top);

    expect(localSlot($top))->toBe('Mon 09:00')
        ->and(localSlot($first))->toBe('Wed 09:00')
        ->and(localSlot($second))->toBe('Fri 09:00');
});

test('a reflow after a delete keeps every post in its slot and the next post fills the gap', function () {
    $first = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $first);
    $second = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $second);
    $third = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $third);

    $first->delete();
    ReflowChannelQueue::handle($this->channel);

    expect(localSlot($second))->toBe('Wed 09:00')
        ->and(localSlot($third))->toBe('Fri 09:00');

    $next = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $next);

    expect(localSlot($next))->toBe('Mon 09:00');
});

test('a custom post on a slot instant occupies that slot for the queue', function () {
    $custom = makeQueuePost($this->channel, [
        'schedule_mode' => ScheduleMode::Custom,
        'scheduled_at' => CarbonImmutable::parse('2026-10-05 09:00', 'America/Sao_Paulo')->utc(),
    ]);

    $queued = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $queued);
    $top = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $top, QueuePosition::Top);

    expect(localSlot($custom))->toBe('Mon 09:00')
        ->and(localSlot($top))->toBe('Wed 09:00')
        ->and(localSlot($queued))->toBe('Fri 09:00');
});

test('two queued posts on one slot keep the first and re-place the second', function () {
    $at = CarbonImmutable::parse('2026-10-07 09:00', 'America/Sao_Paulo')->utc();
    $first = makeQueuePost($this->channel, ['scheduled_at' => $at]);
    $second = makeQueuePost($this->channel, ['scheduled_at' => $at]);

    ReflowChannelQueue::handle($this->channel);

    expect(collect([localSlot($first), localSlot($second)])->sort()->values()->all())->toBe(['Mon 09:00', 'Wed 09:00']);
});

test('changing the schedule reassigns every queued post in order', function () {
    $first = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $first);
    $second = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $second);

    $this->channel->update(['posting_schedule' => PostingSchedule::empty()->withTime(2, '10:00')->withTime(4, '10:00')]);
    ReflowChannelQueue::handle($this->channel);

    expect(localSlot($first))->toBe('Tue 10:00')
        ->and(localSlot($second))->toBe('Thu 10:00');
});

test('posts outside the future queue of the channel are untouched', function () {
    $custom = makeQueuePost($this->channel, ['schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => now()->addDays(3)]);
    $publishing = makeQueuePost($this->channel, ['status' => PostStatus::Publishing, 'scheduled_at' => now()->addDays(3)]);
    $published = makeQueuePost($this->channel, ['status' => PostStatus::Published, 'scheduled_at' => now()->addDays(3)]);
    $draft = makeQueuePost($this->channel, ['status' => PostStatus::Draft, 'scheduled_at' => now()->addDays(3)]);
    $past = makeQueuePost($this->channel, ['scheduled_at' => now()->subDay()]);
    $otherChannel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $other = makeQueuePost($otherChannel, ['scheduled_at' => now()->addDays(3)]);

    $before = collect([$custom, $publishing, $published, $draft, $past, $other])
        ->mapWithKeys(fn (Post $post) => [$post->id => [$post->scheduled_at->toIso8601String(), $post->schedule_mode->value]]);

    $queued = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $queued);

    $after = $before->mapWithKeys(function ($value, $id) {
        $post = Post::find($id);

        return [$id => [$post->scheduled_at->toIso8601String(), $post->schedule_mode->value]];
    });

    expect($after->all())->toEqual($before->all())
        ->and(localSlot($queued))->toBe('Mon 09:00');
});

test('an empty schedule turns future queued posts into custom keeping their time', function () {
    $first = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $first);
    $time = $first->refresh()->scheduled_at->toIso8601String();

    $this->channel->update(['posting_schedule' => PostingSchedule::empty()]);
    ReflowChannelQueue::handle($this->channel);

    $first->refresh();

    expect($first->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($first->scheduled_at->toIso8601String())->toBe($time);
});

test('only changed rows are written', function () {
    $first = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $first);
    $second = makeQueuePost($this->channel);
    ReflowChannelQueue::handle($this->channel, $second);
    $updated = [$first->refresh()->updated_at->toIso8601String(), $second->refresh()->updated_at->toIso8601String()];

    $this->travel(5)->minutes();
    ReflowChannelQueue::handle($this->channel);

    expect([$first->refresh()->updated_at->toIso8601String(), $second->refresh()->updated_at->toIso8601String()])->toBe($updated);
});

test('a held lock makes the reflow throw after the wait timeout', function () {
    $this->travelBack();

    $lock = Cache::lock("queue:{$this->channel->id}", 10);
    expect($lock->get())->toBeTrue();

    expect(fn () => ReflowChannelQueue::handle($this->channel, null, QueuePosition::Next, 1))
        ->toThrow(QueueBusyException::class);

    $lock->release();
});

test('a post the scheduler already claimed is not rewritten', function () {
    $post = makeQueuePost($this->channel, ['scheduled_at' => now()->addDays(2)]);
    $original = $post->refresh()->scheduled_at->toIso8601String();
    DB::table('posts')->where('id', $post->id)->update(['status' => PostStatus::Publishing->value]);

    $written = ReflowChannelQueue::updateIfScheduled($post, ['scheduled_at' => now()->addDays(9), 'schedule_mode' => ScheduleMode::Custom]);

    $fresh = Post::find($post->id);

    expect($written)->toBeFalse()
        ->and($fresh->scheduled_at->toIso8601String())->toBe($original)
        ->and($fresh->schedule_mode)->toBe(ScheduleMode::Queue);
});

test('a post switched to a custom time after the reflow selected it is not rewritten', function () {
    $post = makeQueuePost($this->channel, ['scheduled_at' => now()->addDays(2)]);
    $custom = now()->addDays(4)->startOfMinute();
    DB::table('posts')->where('id', $post->id)->update([
        'schedule_mode' => ScheduleMode::Custom->value,
        'scheduled_at' => $custom,
    ]);

    $written = ReflowChannelQueue::updateIfScheduled($post, ['scheduled_at' => now()->addDays(9), 'schedule_mode' => ScheduleMode::Queue]);

    $fresh = Post::find($post->id);

    expect($written)->toBeFalse()
        ->and($fresh->scheduled_at->equalTo($custom))->toBeTrue()
        ->and($fresh->schedule_mode)->toBe(ScheduleMode::Custom);
});

test('a post due within the next minute is left alone while later posts still reflow', function () {
    $imminent = makeQueuePost($this->channel, ['scheduled_at' => now()->addSeconds(30)]);
    $imminentTime = $imminent->refresh()->scheduled_at->toIso8601String();
    $later = makeQueuePost($this->channel, ['scheduled_at' => now()->addDays(20)]);

    $this->channel->update(['posting_schedule' => PostingSchedule::empty()->withTime(2, '10:00')]);
    ReflowChannelQueue::handle($this->channel);

    expect($imminent->refresh()->scheduled_at->toIso8601String())->toBe($imminentTime)
        ->and(localSlot($later))->toBe('Tue 10:00');
});

test('withLock releases earlier locks when a later one is busy', function () {
    $this->travelBack();
    $other = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $ids = collect([$this->channel->id, $other->id])->sort()->values();

    $busy = Cache::lock("queue:{$ids[1]}", 10);
    expect($busy->get())->toBeTrue();

    expect(fn () => ReflowChannelQueue::withLock($ids->all(), fn () => null, 1))->toThrow(QueueBusyException::class);

    $first = Cache::lock("queue:{$ids[0]}", 10);
    expect($first->get())->toBeTrue();

    $first->release();
    $busy->release();
});
