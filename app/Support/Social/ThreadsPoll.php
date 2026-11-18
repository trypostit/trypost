<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\Analytics\PollVotingStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use Carbon\CarbonImmutable;

/**
 * The poll a Threads text post may carry (`meta.poll`, content type
 * `threads_poll`): two to four choices of up to 25 characters. Threads sets
 * how long it stays open, so there is no length to pick, and it is sent as
 * `poll_attachment` (`option_a` to `option_d`) on a text post only.
 *
 * @see https://developers.facebook.com/documentation/threads/create-posts/polls
 */
final class ThreadsPoll extends Poll
{
    public const int OPTION_MAX_LENGTH = 25;

    /** The keys Threads names the choices by, in order. */
    private const array OPTION_KEYS = ['option_a', 'option_b', 'option_c', 'option_d'];

    /**
     * The `poll_attachment` fields a post read asks Threads for.
     */
    public const string LOOKUP_FIELDS = 'poll_attachment{option_a,option_b,option_c,option_d,option_a_votes_percentage,option_b_votes_percentage,option_c_votes_percentage,option_d_votes_percentage,total_votes,expiration_timestamp}';

    public static function platform(): Platform
    {
        return Platform::Threads;
    }

    public static function contentType(): ContentType
    {
        return ContentType::ThreadsPoll;
    }

    /**
     * The `poll_attachment` field of the text post container.
     *
     * @return array<string, string>
     */
    public static function payload(?array $meta): array
    {
        return collect(self::options($meta))
            ->take(self::MAX_OPTIONS)
            ->mapWithKeys(fn (string $option, int $index): array => [self::OPTION_KEYS[$index] => $option])
            ->all();
    }

    /**
     * A post's `poll_attachment` reduced to what is stored. Threads gives each
     * choice its share of `total_votes` as a decimal (`0.10` for 10%), so the
     * votes are worked out from it; a poll is closed once its
     * `expiration_timestamp` has passed.
     *
     * @param  array<string, mixed>  $poll
     * @return array{options: list<array{label: string, votes: int}>, voting_status: ?string, end_datetime: ?string, duration_minutes: null}
     */
    public static function result(array $poll): array
    {
        $total = (int) data_get($poll, 'total_votes', 0);
        $choices = collect(self::OPTION_KEYS)->filter(fn (string $key): bool => filled(data_get($poll, $key)));
        $endsAt = transform(data_get($poll, 'expiration_timestamp'), fn (mixed $at): CarbonImmutable => CarbonImmutable::parse((string) $at)->utc());

        return [
            'options' => $choices
                ->map(fn (string $key): array => [
                    'label' => (string) data_get($poll, $key),
                    'votes' => (int) round((float) data_get($poll, "{$key}_votes_percentage", 0) * $total),
                ])
                ->values()
                ->all(),
            'voting_status' => transform($endsAt, fn (CarbonImmutable $at): string => self::votingStatus($at)->value),
            'end_datetime' => $endsAt?->toIso8601ZuluString(),
            'duration_minutes' => null,
        ];
    }

    private static function votingStatus(CarbonImmutable $endsAt): PollVotingStatus
    {
        if ($endsAt->isPast()) {
            return PollVotingStatus::Closed;
        }

        return PollVotingStatus::Open;
    }
}
