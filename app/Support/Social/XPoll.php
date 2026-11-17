<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\Analytics\PollVotingStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use Illuminate\Support\Collection;

/**
 * The poll an X post may carry (`meta.poll`, content type `x_poll`), or any
 * reply of its thread: two to four choices of up to 25 characters and how
 * long voting stays open, with the question capped at 280 characters even on
 * an account with long posts.
 */
final class XPoll extends Poll
{
    public const int OPTION_MAX_LENGTH = 25;

    public const ?int MIN_DURATION = 5;

    public const ?int MAX_DURATION = 10080;

    public const ?int DEFAULT_DURATION = 1440;

    public const ?int QUESTION_MAX_LENGTH = 280;

    /**
     * The `poll.fields` every tweet lookup asks X for.
     */
    public const string LOOKUP_FIELDS = 'options,duration_minutes,end_datetime,voting_status';

    public static function platform(): Platform
    {
        return Platform::X;
    }

    public static function contentType(): ContentType
    {
        return ContentType::XPoll;
    }

    /**
     * One X poll object as the tweet lookup returns it, reduced to what is stored.
     *
     * @param  array<string, mixed>  $poll
     * @return array{options: list<array{label: string, votes: int}>, voting_status: ?string, end_datetime: ?string, duration_minutes: ?int}
     */
    public static function result(array $poll): array
    {
        return [
            'options' => Collection::wrap(data_get($poll, 'options'))
                ->sortBy(fn (mixed $option): int => (int) data_get($option, 'position', 0))
                ->map(fn (mixed $option): array => [
                    'label' => (string) data_get($option, 'label', ''),
                    'votes' => (int) data_get($option, 'votes', 0),
                ])
                ->values()
                ->all(),
            'voting_status' => PollVotingStatus::tryFrom((string) data_get($poll, 'voting_status'))?->value,
            'end_datetime' => data_get($poll, 'end_datetime'),
            'duration_minutes' => self::minutes(data_get($poll, 'duration_minutes')),
        ];
    }

    /**
     * The `poll` field of the create-post request: the poll as it is
     * previewed, its choices and its length.
     *
     * @return array{options: list<string>, duration_minutes: int}
     */
    public static function payload(?array $meta): array
    {
        return self::preview($meta);
    }
}
