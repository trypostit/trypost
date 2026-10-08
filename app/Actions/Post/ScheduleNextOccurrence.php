<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
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
 * counted from the series origin in the channel's time zone, so a month-end or
 * leap-day series never drifts to the clamped day and keeps its local time across
 * daylight saving changes. Runs inside the transaction that settles the post, so a
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
        $targets = $post->loadMissing('socialAccount')->socialAccount !== null ? collect([$post]) : collect();
        $author = $post->user;
        $user = $author !== null && ($author->ownsAccountOf($post->workspace) || $author->belongsToWorkspace($post->workspace))
            ? $author
            : $post->workspace->owner;

        if ($current === null || $targets->isEmpty() || $user === null) {
            $post->update(Post::withoutRecurrence());

            return collect();
        }

        $frequency = $post->recurrence_frequency;
        $interval = (int) $post->recurrence_interval;
        $remaining = (int) $post->recurrence_remaining;
        $ceiling = CarbonImmutable::parse(PostingSchedule::MAX_INSTANT, 'UTC');

        $plans = $targets
            ->map(function (Post $target) use ($post, $current, $frequency, $interval, $remaining): array {
                [$origin, $index] = self::position($post->recurrence_origin_at, $current, $frequency, $interval, Timezone::normalize($target->socialAccount->timezone));

                $steps = 1;
                $next = $frequency->advance($origin, $interval * ($index + $steps));

                while ($steps <= $remaining && $next->lessThanOrEqualTo(now())) {
                    $steps++;
                    $next = $frequency->advance($origin, $interval * ($index + $steps));
                }

                return ['target' => $target, 'origin' => $origin, 'next' => $next, 'left' => $remaining - $steps];
            })
            ->filter(fn (array $plan): bool => $plan['left'] >= 0 && $plan['next']->lessThanOrEqualTo($ceiling))
            ->values();

        if ($plans->isEmpty()) {
            $post->update(Post::withoutRecurrence());

            return collect();
        }

        try {
            return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($post, $plans, $user, $frequency, $interval): Collection {
                $groupId = (string) Str::uuid7();
                $occurrences = $plans->map(function (array $plan) use ($post, $user, $batch, $groupId, $frequency, $interval): Post {
                    $occurrence = CreateChannelPost::execute($post->workspace, $user, [
                        ...DuplicatePost::destination($post),
                        'post_group_id' => $groupId,
                        'status' => PostStatus::Scheduled->value,
                        'scheduled_at' => $plan['next']->utc()->toIso8601String(),
                        'created_via' => $post->created_via,
                    ], $batch);

                    if ($plan['left'] > 0) {
                        $occurrence->update([
                            'recurrence_interval' => $interval,
                            'recurrence_frequency' => $frequency,
                            'recurrence_remaining' => $plan['left'],
                            'recurrence_origin_at' => $plan['origin']->utc(),
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
     * The series origin in the channel's time zone and the index of the current
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
