<?php

declare(strict_types=1);

namespace App\Actions\Post\Queue;

use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Exceptions\Post\QueueBusyException;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ReflowChannelQueue
{
    /**
     * Callers that enqueue inside a DB transaction must take the lock outside it:
     * withLock($ids, fn () => DB::transaction(fn () => handleLocked(...))).
     * Cache::lock()->block() never times out under frozen test time; call travelBack() before contention tests.
     *
     * @param  list<string>  $channelIds
     */
    public static function withLock(array $channelIds, Closure $callback, int $waitSeconds = 5): mixed
    {
        $ids = array_values(array_unique($channelIds));
        sort($ids);
        $locks = [];

        try {
            foreach ($ids as $id) {
                $lock = Cache::lock("queue:{$id}", 10);
                $lock->block($waitSeconds);
                $locks[] = $lock;
            }

            return $callback();
        } catch (LockTimeoutException) {
            throw new QueueBusyException;
        } finally {
            foreach (array_reverse($locks) as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * Takes the channel lock itself. Do not call inside a DB transaction; use withLock() around the transaction instead.
     */
    public static function handle(SocialAccount $channel, ?Post $insert = null, QueuePosition $position = QueuePosition::Next, int $waitSeconds = 5, ?string $previousTimezone = null): void
    {
        self::withLock([$channel->id], fn () => self::handleLocked($channel, $insert, $position, $previousTimezone), $waitSeconds);
    }

    /**
     * Reflows the channel once the surrounding transaction commits (immediately outside one).
     * A channel deleted meanwhile is skipped; a busy lock is reported and leaves the previous, still valid times.
     */
    public static function afterCommit(string $channelId, ?string $previousTimezone = null): void
    {
        DB::afterCommit(function () use ($channelId, $previousTimezone): void {
            $channel = SocialAccount::query()->find($channelId);

            if ($channel === null) {
                return;
            }

            try {
                self::handle($channel, previousTimezone: $previousTimezone);
            } catch (QueueBusyException $exception) {
                report($exception);
            }
        });
    }

    /**
     * Queue slots (user decision 2026-10-02): a queued post keeps its slot while that slot still
     * exists in the posting schedule; nothing packs the queue. Next takes the first free slot,
     * Top takes the first slot and shifts the run of queued posts behind it up to the first gap,
     * and only posts whose slot vanished are re-placed into the first free slots. On a time zone
     * change every queued post first tries the slot at its same local clock time in the new zone, queue requests pending approval included.
     * Any scheduled post, and any queue request pending approval, on a slot instant occupies it. A post without a slot left becomes custom at its time.
     */
    public static function handleLocked(SocialAccount $channel, ?Post $insert = null, QueuePosition $position = QueuePosition::Next, ?string $previousTimezone = null): void
    {
        $channel->refresh();
        $after = now()->addMinute();
        $schedule = $channel->posting_schedule;
        $timezone = $channel->timezone;

        $queued = Post::query()
            ->queuedOn($channel->id, $after)
            ->when($insert, fn ($query) => $query->whereKeyNot($insert->id))
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get();

        $timezoneChanged = $previousTimezone !== null && $previousTimezone !== $timezone;

        if ($timezoneChanged) {
            $queued = $queued
                ->concat(Post::query()->pendingQueueRequestsOn($channel->id, $after)->get())
                ->sortBy(fn (Post $post): string => "{$post->scheduled_at->getTimestamp()}{$post->id}")
                ->values();
        }

        $taken = Post::query()
            ->occupyingSlotsOn($channel->id, $after)
            ->when($insert, fn ($query) => $query->whereKeyNot($insert->id))
            ->when($timezoneChanged, fn ($query) => $query->whereKeyNot($queued->modelKeys()))
            ->pluck('scheduled_at')
            ->mapWithKeys(fn (CarbonInterface $at): array => [$at->getTimestamp() => true])
            ->all();

        $placed = [];
        $displaced = [];

        foreach ($queued as $post) {
            $slot = $timezoneChanged ? self::sameLocalClock($post->scheduled_at, $previousTimezone, $timezone) : $post->scheduled_at;
            $key = $slot->getTimestamp();

            if ($slot->greaterThan($after) && $schedule?->hasSlotAt($slot, $timezone) && ! isset($placed[$key]) && ! ($timezoneChanged && isset($taken[$key]))) {
                $placed[$key] = $post;
                $taken[$key] = true;
            } else {
                $displaced[] = $post;
            }
        }

        $targets = [];

        if ($insert !== null && $position === QueuePosition::Top) {
            $run = [$insert];

            foreach ($schedule?->slotsAfter($after, $timezone) ?? [] as $slot) {
                $key = $slot->getTimestamp();

                if (isset($placed[$key])) {
                    $run[] = $placed[$key];
                    unset($placed[$key]);
                    $targets[array_shift($run)->id] = $slot;

                    continue;
                }

                if (! isset($taken[$key])) {
                    $targets[array_shift($run)->id] = $slot;
                    $taken[$key] = true;
                    $run = [];

                    break;
                }
            }

            $displaced = [...$run, ...$displaced];
        } elseif ($insert !== null) {
            $displaced[] = $insert;
        }

        foreach ($placed as $key => $post) {
            $targets[$post->id] = CarbonImmutable::createFromTimestampUTC($key);
        }

        if ($displaced !== []) {
            foreach ($schedule?->slotsAfter($after, $timezone) ?? [] as $slot) {
                if (isset($taken[$slot->getTimestamp()])) {
                    continue;
                }

                $targets[array_shift($displaced)->id] = $slot;
                $taken[$slot->getTimestamp()] = true;

                if ($displaced === []) {
                    break;
                }
            }
        }

        $posts = $insert ? $queued->push($insert) : $queued;

        DB::transaction(function () use ($posts, $targets): void {
            foreach ($posts as $post) {
                $slot = $targets[$post->id] ?? null;

                if ($slot === null) {
                    if ($post->scheduled_at !== null && $post->status === PostStatus::Scheduled) {
                        self::updateIfScheduled($post, ['schedule_mode' => ScheduleMode::Custom]);
                    }

                    continue;
                }

                if ($post->scheduled_at === null || ! $post->scheduled_at->equalTo($slot)) {
                    self::updateIfHolding($post, ['scheduled_at' => $slot, 'schedule_mode' => ScheduleMode::Queue]);
                }
            }
        });
    }

    public static function isFreeSlot(SocialAccount $channel, CarbonInterface $slotAt, ?string $exceptPostId = null): bool
    {
        $after = now()->addMinute();

        return $slotAt->greaterThan($after)
            && $channel->posting_schedule?->hasSlotAt($slotAt, $channel->timezone)
            && ! Post::query()->occupyingSlotsOn($channel->id, $after)
                ->when($exceptPostId, fn ($query) => $query->whereKeyNot($exceptPostId))
                ->where('scheduled_at', $slotAt)
                ->exists();
    }

    private static function sameLocalClock(CarbonInterface $at, string $from, string $to): CarbonImmutable
    {
        $local = $at->setTimezone($from);

        return CarbonImmutable::create($local->year, $local->month, $local->day, $local->hour, $local->minute, 0, $to)->utc();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function updateIfHolding(Post $post, array $attributes): bool
    {
        if ($post->status === PostStatus::Scheduled) {
            return self::updateIfScheduled($post, $attributes);
        }

        $affected = Post::query()
            ->whereKey($post->id)
            ->where('status', PostStatus::PendingApproval)
            ->where('schedule_mode', ScheduleMode::Queue)
            ->update($attributes);

        if ($affected === 1) {
            $post->forceFill($attributes)->syncOriginal();
        }

        return $affected === 1;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function updateIfScheduled(Post $post, array $attributes): bool
    {
        $affected = Post::query()
            ->whereKey($post->id)
            ->where('status', PostStatus::Scheduled)
            ->where('schedule_mode', ScheduleMode::Queue)
            ->update($attributes);

        if ($affected === 1) {
            $post->forceFill($attributes)->syncOriginal();
        }

        return $affected === 1;
    }
}
