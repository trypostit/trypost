<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostingSchedule;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Continues a recurring series once one of its posts has settled, whether it
 * published or failed: the post hands its rule to a copy for each enabled channel,
 * scheduled for the next occurrence still in the future. Every occurrence is
 * counted from the series origin, so a month-end or leap-day series never drifts
 * to the clamped day. Runs inside the transaction that settles the post, so a
 * retried settlement never creates a second copy; a failed copy leaves the rule
 * on the post for the next settlement.
 */
class ScheduleNextOccurrence
{
    /**
     * @return Collection<int, Post>
     */
    public static function execute(Post $post): Collection
    {
        if (! $post->isRecurring()) {
            return collect();
        }

        $current = $post->currentOccurrence();
        $targets = $post->postPlatforms()->enabled()->whereHas('socialAccount')->get();
        $user = $post->user ?? $post->workspace->owner;

        if ($current === null || $targets->isEmpty() || $user === null) {
            $post->update(Post::withoutRecurrence());

            return collect();
        }

        $frequency = $post->recurrence_frequency;
        $interval = (int) $post->recurrence_interval;
        $remaining = (int) $post->recurrence_remaining;
        [$origin, $index] = self::position($post->recurrence_origin_at, $current, $frequency, $interval, Timezone::normalize($user->timezone));

        $steps = 1;
        $next = $frequency->advance($origin, $interval * ($index + $steps));

        while ($steps <= $remaining && $next->lessThanOrEqualTo(now())) {
            $steps++;
            $next = $frequency->advance($origin, $interval * ($index + $steps));
        }

        if ($steps > $remaining || $next->greaterThan(CarbonImmutable::parse(PostingSchedule::MAX_INSTANT, 'UTC'))) {
            $post->update(Post::withoutRecurrence());

            return collect();
        }

        $left = $remaining - $steps;

        try {
            return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($post, $targets, $user, $next, $left, $frequency, $interval, $origin): Collection {
                $groupId = (string) Str::uuid7();
                $occurrences = $targets->map(function (PostPlatform $target) use ($post, $user, $batch, $groupId, $next, $left, $frequency, $interval, $origin): Post {
                    $occurrence = CreateChannelPost::execute($post->workspace, $user, [
                        ...DuplicatePost::destination($post, $target),
                        'post_group_id' => $groupId,
                        'status' => PostStatus::Scheduled->value,
                        'scheduled_at' => $next->utc()->toIso8601String(),
                        'created_via' => $post->created_via,
                    ], $batch);

                    if ($left > 0) {
                        $occurrence->update([
                            'recurrence_interval' => $interval,
                            'recurrence_frequency' => $frequency,
                            'recurrence_remaining' => $left,
                            'recurrence_origin_at' => $origin->utc(),
                        ]);
                    }

                    return $occurrence;
                })->values();

                $post->update(Post::withoutRecurrence());

                return $occurrences;
            });
        } catch (Throwable $exception) {
            report($exception);

            return collect();
        }
    }

    /**
     * The series origin in the author's time zone and the index of the current
     * occurrence in it. A post moved off its series starts a new one at its time.
     *
     * @return array{0: CarbonImmutable, 1: int}
     */
    private static function position(?CarbonInterface $origin, CarbonInterface $current, RecurrenceFrequency $frequency, int $interval, string $timezone): array
    {
        if ($origin !== null) {
            $local = $origin->toImmutable()->setTimezone($timezone);
            $index = 0;

            while (($occurrence = $frequency->advance($local, $interval * $index))->lessThan($current)) {
                $index++;
            }

            if ($occurrence->equalTo($current)) {
                return [$local, $index];
            }
        }

        return [$current->toImmutable()->setTimezone($timezone), 0];
    }
}
