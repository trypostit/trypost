<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Arr;
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
     * The poll's choices, trimmed, without blanks.
     *
     * @return list<string>
     */
    public static function options(mixed $meta): array
    {
        return collect(Arr::wrap(data_get($meta, 'poll.options')))
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
        $normalized = ['options' => self::options(['poll' => $poll])];

        if (filled(data_get($poll, 'duration_minutes'))) {
            $normalized['duration_minutes'] = (int) data_get($poll, 'duration_minutes');
        }

        return $normalized;
    }

    /**
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function violation(mixed $meta): ?array
    {
        $duplicates = collect(self::options($meta))->map(fn (string $option): string => Str::lower($option))->duplicates();

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
     * @return array{options: list<array{label: string, votes: int}>, voting_status: string, end_datetime: ?string, duration_minutes: ?int}
     */
    public static function result(array $poll): array
    {
        return [
            'options' => collect(Arr::wrap(data_get($poll, 'options')))
                ->sortBy(fn (mixed $option): int => (int) data_get($option, 'position', 0))
                ->map(fn (mixed $option): array => [
                    'label' => (string) data_get($option, 'label', ''),
                    'votes' => (int) data_get($option, 'votes', 0),
                ])
                ->values()
                ->all(),
            'voting_status' => (string) data_get($poll, 'voting_status', 'closed'),
            'end_datetime' => data_get($poll, 'end_datetime'),
            'duration_minutes' => transform(data_get($poll, 'duration_minutes'), fn (mixed $minutes): int => (int) $minutes),
        ];
    }

    /**
     * A stored poll result turned into the post's poll meta.
     *
     * @param  array<string, mixed>  $result
     * @return array{options: list<string>, duration_minutes: int}
     */
    public static function definition(array $result): array
    {
        return [
            'options' => collect(Arr::wrap(data_get($result, 'options')))
                ->map(fn (mixed $option): string => (string) data_get($option, 'label', ''))
                ->values()
                ->all(),
            'duration_minutes' => transform(data_get($result, 'duration_minutes'), fn (mixed $minutes): int => (int) $minutes, self::DEFAULT_DURATION),
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
            'duration_minutes' => transform(data_get($meta, 'poll.duration_minutes'), fn (mixed $minutes): int => (int) $minutes, self::DEFAULT_DURATION),
        ];
    }
}
