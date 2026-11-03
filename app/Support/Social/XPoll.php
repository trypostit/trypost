<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\SocialAccount\Platform;
use App\Enums\X\PollVotingStatus;
use App\Models\AnalyticsPublication;
use App\Services\Social\ContentSanitizer;
use Carbon\CarbonInterface;
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
     * The limits the composer reads from `Platform::publishConfig()`, so they are never copied into TypeScript.
     *
     * @return array{minOptions: int, maxOptions: int, optionMaxLength: int, minDuration: int, maxDuration: int, defaultDuration: int, questionMaxLength: int}
     */
    public static function limits(): array
    {
        return [
            'minOptions' => self::MIN_OPTIONS,
            'maxOptions' => self::MAX_OPTIONS,
            'optionMaxLength' => self::OPTION_MAX_LENGTH,
            'minDuration' => self::MIN_DURATION,
            'maxDuration' => self::MAX_DURATION,
            'defaultDuration' => self::DEFAULT_DURATION,
            'questionMaxLength' => self::QUESTION_MAX_LENGTH,
        ];
    }

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
     * Too many choices once blanks are dropped, refused at every save.
     */
    public static function optionsCountViolation(mixed $options): ?string
    {
        return when(
            count(self::choices($options)) > self::MAX_OPTIONS,
            fn (): string => trans('posts.form.x.poll.options_max', ['max' => self::MAX_OPTIONS]),
        );
    }

    /**
     * A choice longer than X allows once trimmed, refused at every save.
     */
    public static function choiceLengthViolation(mixed $choice): ?string
    {
        $tooLong = is_string($choice) && Str::length(Str::trim($choice)) > self::OPTION_MAX_LENGTH;

        return when($tooLong, fn (): string => trans('posts.form.x.poll.choice_max', ['max' => self::OPTION_MAX_LENGTH]));
    }

    /**
     * "Add at least 2 choices", with the minimum from X's rules.
     */
    public static function optionsRequiredMessage(): string
    {
        return trans('posts.form.x.poll.options_required', ['min' => self::MIN_OPTIONS]);
    }

    /**
     * "From 5 minutes to 7 days", with the range from X's rules.
     */
    public static function durationRangeMessage(): string
    {
        $maxDays = intdiv(self::MAX_DURATION, CarbonInterface::MINUTES_PER_HOUR * CarbonInterface::HOURS_PER_DAY);

        return trans('posts.form.x.poll.duration_range', ['min' => self::MIN_DURATION, 'max' => $maxDays]);
    }

    /**
     * What a poll needs before its post is scheduled or published.
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function requiredViolation(mixed $meta): ?array
    {
        if (count(self::options($meta)) < self::MIN_OPTIONS) {
            return ['poll.options', self::optionsRequiredMessage()];
        }

        if (blank(data_get($meta, 'poll.duration_minutes'))) {
            return ['poll.duration_minutes', trans('posts.form.x.poll.duration_required')];
        }

        return null;
    }

    /**
     * A poll's question is the post text: it cannot be scheduled or published
     * without one. It is measured as X receives it, so editor markup with no
     * words (`<p></p>`) counts as no question.
     */
    public static function questionViolation(?string $content): ?string
    {
        $question = app(ContentSanitizer::class)->displayText((string) $content, Platform::X);

        return when(blank($question), fn (): string => trans('posts.form.x.poll.question_required'));
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
     * The poll meta of an X poll found on the network, read from what discovery stored.
     *
     * @return array{options: list<string>, duration_minutes: int}
     */
    public static function fromPublication(AnalyticsPublication $publication): array
    {
        $poll = data_get($publication->provider_metadata, 'poll');

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
