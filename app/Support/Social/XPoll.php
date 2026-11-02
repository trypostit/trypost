<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The poll an X post may carry (`meta.poll`, content type `x_poll`): two to
 * four short choices and how long voting stays open. Blank choices are dropped
 * when the meta is normalized, so the rules below read the cleaned list.
 */
final class XPoll
{
    public const int MIN_OPTIONS = 2;

    public const int MAX_OPTIONS = 4;

    public const int OPTION_MAX_LENGTH = 25;

    public const int MIN_DURATION = 5;

    public const int MAX_DURATION = 10080;

    public const int DEFAULT_DURATION = 1440;

    public const int QUESTION_MAX_LENGTH = 280;

    /**
     * The `poll.fields` every tweet lookup asks X for.
     */
    public const string LOOKUP_FIELDS = 'options,duration_minutes,end_datetime,voting_status';

    /**
     * The voting states X reports; anything else is stored as unknown (null).
     *
     * @var list<string>
     */
    private const array VOTING_STATUSES = ['open', 'closed'];

    /**
     * The poll's choices, trimmed, without blanks.
     *
     * @return list<string>
     */
    public static function options(mixed $meta): array
    {
        return self::choices(data_get($meta, 'poll.options'));
    }

    /**
     * A list of choices, trimmed, without blanks.
     *
     * @return list<string>
     */
    public static function choices(mixed $options): array
    {
        return Collection::wrap($options)
            ->filter(fn (mixed $option): bool => is_string($option))
            ->map(fn (string $option): string => Str::trim($option))
            ->filter(fn (string $option): bool => filled($option))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $poll
     * @return array{options: list<string>, duration_minutes?: int}
     */
    public static function normalize(array $poll): array
    {
        return Arr::whereNotNull([
            'options' => self::choices(data_get($poll, 'options')),
            'duration_minutes' => self::minutes(data_get($poll, 'duration_minutes')),
        ]);
    }

    /**
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function violation(mixed $meta): ?array
    {
        $duplicates = collect(self::options($meta))->map(fn (string $option): string => Str::lower($option))->duplicatesStrict();

        if ($duplicates->isNotEmpty()) {
            return ['poll.options', trans('posts.form.x.poll.options_distinct')];
        }

        return null;
    }

    /**
     * What a poll needs before its post is scheduled or published.
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function requiredViolation(mixed $meta): ?array
    {
        if (count(self::options($meta)) < self::MIN_OPTIONS) {
            return ['poll.options', trans('posts.form.x.poll.options_required')];
        }

        if (blank(data_get($meta, 'poll.duration_minutes'))) {
            return ['poll.duration_minutes', trans('posts.form.x.poll.duration_required')];
        }

        return null;
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
            'voting_status' => in_array(data_get($poll, 'voting_status'), self::VOTING_STATUSES, true) ? data_get($poll, 'voting_status') : null,
            'end_datetime' => data_get($poll, 'end_datetime'),
            'duration_minutes' => self::minutes(data_get($poll, 'duration_minutes')),
        ];
    }

    /**
     * The poll meta of an X post found on the network, or null when it carries no poll.
     *
     * @return array{options: list<string>, duration_minutes: int}|null
     */
    public static function fromPublication(AnalyticsPublication $publication): ?array
    {
        $poll = data_get($publication->provider_metadata, 'poll');

        if ($publication->platform !== Platform::X || ! is_array($poll) || blank($poll)) {
            return null;
        }

        return [
            'options' => Collection::wrap(data_get($poll, 'options'))->pluck('label')->all(),
            'duration_minutes' => self::minutes(data_get($poll, 'duration_minutes'), self::DEFAULT_DURATION),
        ];
    }

    /**
     * The `poll` field of the create-post request.
     *
     * @return array{options: list<string>, duration_minutes: int}
     */
    public static function payload(mixed $meta): array
    {
        return [
            'options' => self::options($meta),
            'duration_minutes' => self::minutes(data_get($meta, 'poll.duration_minutes'), self::DEFAULT_DURATION),
        ];
    }

    private static function minutes(mixed $value, ?int $default = null): ?int
    {
        return transform($value, fn (mixed $minutes): int => (int) $minutes, $default);
    }
}
