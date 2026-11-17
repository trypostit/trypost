<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\Analytics\PollVotingStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * The poll a Mastodon status may carry (`meta.poll`, content type
 * `mastodon_poll`), or any reply of its thread: two to four choices of up to
 * 50 characters, open from 5 minutes to 30 days, with a single or multiple
 * choice and totals shown or hidden until it ends. These are the limits of
 * mastodon.social; an instance may set others, which are not read.
 *
 * @see https://docs.joinmastodon.org/methods/statuses/#create
 */
final class MastodonPoll extends Poll
{
    public const int OPTION_MAX_LENGTH = 50;

    public const ?int MIN_DURATION = 5;

    public const ?int MAX_DURATION = 43200;

    public const ?int DEFAULT_DURATION = 1440;

    public const bool MULTIPLE_CHOICE = true;

    public const bool HIDE_TOTALS = true;

    public static function platform(): Platform
    {
        return Platform::Mastodon;
    }

    public static function contentType(): ContentType
    {
        return ContentType::MastodonPoll;
    }

    /**
     * The `poll` field of the create-status request (`expires_in` is in seconds).
     *
     * @return array{options: list<string>, expires_in: int, multiple: bool, hide_totals: bool}
     */
    public static function payload(mixed $meta): array
    {
        $minutes = self::minutes(data_get($meta, 'poll.duration_minutes'), self::DEFAULT_DURATION);

        return [
            'options' => self::options($meta),
            'expires_in' => $minutes * CarbonInterface::SECONDS_PER_MINUTE,
            'multiple' => data_get($meta, 'poll.multiple') === true,
            'hide_totals' => data_get($meta, 'poll.hide_totals') === true,
        ];
    }

    /**
     * A status's `poll` reduced to what is stored. A total Mastodon hides
     * until the poll ends reads as no votes.
     *
     * @param  array<string, mixed>  $poll
     * @return array{options: list<array{label: string, votes: int}>, voting_status: ?string, end_datetime: ?string, duration_minutes: null, multiple: bool}
     */
    public static function result(array $poll): array
    {
        $expired = data_get($poll, 'expired');

        return [
            'options' => Collection::wrap(data_get($poll, 'options'))
                ->map(fn (mixed $option): array => [
                    'label' => (string) data_get($option, 'title', ''),
                    'votes' => (int) data_get($option, 'votes_count', 0),
                ])
                ->values()
                ->all(),
            'voting_status' => self::votingStatus($expired)?->value,
            'end_datetime' => data_get($poll, 'expires_at'),
            'duration_minutes' => null,
            'multiple' => data_get($poll, 'multiple') === true,
        ];
    }

    /**
     * A status's poll result as `provider_metadata` keeps it, from the status
     * the collectors already read; nothing for a status without a poll.
     *
     * @param  array<string, mixed>  $status
     * @return array{poll?: array<string, mixed>}
     */
    public static function metadataFrom(array $status): array
    {
        $poll = data_get($status, 'poll');

        if (! is_array($poll)) {
            return [];
        }

        return ['poll' => self::result($poll)];
    }

    /**
     * Its length is the time from publishing to its end, within Mastodon's range.
     *
     * @return array{options: list<string>, duration_minutes: int, multiple: bool}
     */
    public static function fromPublication(AnalyticsPublication $publication): array
    {
        $poll = data_get($publication->provider_metadata, 'poll');

        return [
            'options' => Collection::wrap(data_get($poll, 'options'))->pluck('label')->all(),
            'duration_minutes' => self::durationFrom($publication->provider_published_at, data_get($poll, 'end_datetime')),
            'multiple' => data_get($poll, 'multiple') === true,
        ];
    }

    private static function votingStatus(mixed $expired): ?PollVotingStatus
    {
        if ($expired === true) {
            return PollVotingStatus::Closed;
        }

        if ($expired === false) {
            return PollVotingStatus::Open;
        }

        return null;
    }

    private static function durationFrom(?CarbonInterface $publishedAt, mixed $endsAt): int
    {
        if (blank($publishedAt) || ! is_string($endsAt)) {
            return self::DEFAULT_DURATION;
        }

        $minutes = (int) round($publishedAt->diffInMinutes(CarbonImmutable::parse($endsAt)));

        return (int) Number::clamp($minutes, self::MIN_DURATION, self::MAX_DURATION);
    }
}
