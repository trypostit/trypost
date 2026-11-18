<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Services\Social\ContentSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The poll a post may carry (`meta.poll`, on a poll content type) or a thread
 * reply may carry (`{text, media, poll}`): two to four short choices, and on
 * the networks that let the author pick them, how long voting stays open,
 * whether a voter may pick several choices and whether the totals stay hidden
 * until it ends. Each network's rules live in its subclass (`XPoll`,
 * `MastodonPoll`, `ThreadsPoll`), which `for()` and `ContentType::poll()` pick;
 * the post text is always the question. Blank choices are dropped when the
 * meta is normalized, so the rules below read the cleaned list.
 *
 * A `mixed` parameter here is a value as it came in, whose shape its source
 * does not guarantee: a choice or a switch as sent, before validation, or a
 * poll as the network's JSON returned it. Everything else takes one type.
 */
abstract class Poll
{
    public const int MIN_OPTIONS = 2;

    public const int MAX_OPTIONS = 4;

    /** The longest choice the network takes, counted by `choiceLength()`. */
    public const int OPTION_MAX_LENGTH = 25;

    /** The shortest and longest a poll runs, in minutes; null on a network that sets it itself. */
    public const ?int MIN_DURATION = null;

    public const ?int MAX_DURATION = null;

    public const ?int DEFAULT_DURATION = null;

    /** A cap on the question below the account's text limit, or null for none. */
    public const ?int QUESTION_MAX_LENGTH = null;

    /** Whether the author may let a voter pick several choices (`poll.multiple`). */
    public const bool MULTIPLE_CHOICE = false;

    /** Whether the author may hide the totals until voting ends (`poll.hide_totals`). */
    public const bool HIDE_TOTALS = false;

    /** The network the poll publishes on. */
    abstract public static function platform(): Platform;

    /** The content type of a post that is this network's poll. */
    abstract public static function contentType(): ContentType;

    /**
     * The poll as the network's create request takes it.
     *
     * @return array<string, mixed>
     */
    abstract public static function payload(?array $meta): array;

    /**
     * One poll as the network returns it, reduced to what is stored as its
     * result (`provider_metadata.poll`).
     *
     * @param  array<string, mixed>  $poll
     * @return array<string, mixed>
     */
    abstract public static function result(array $poll): array;

    /**
     * The poll meta of a poll found on the network, read from its stored
     * result: its choices, and its length (else the default) where the
     * author picks one.
     *
     * @return array<string, mixed>
     */
    public static function fromPublication(AnalyticsPublication $publication): array
    {
        $poll = data_get($publication->provider_metadata, 'poll');
        $meta = ['options' => Collection::wrap(data_get($poll, 'options'))->pluck('label')->all()];

        if (static::hasDuration()) {
            $meta['duration_minutes'] = self::minutes(data_get($poll, 'duration_minutes'), static::DEFAULT_DURATION);
        }

        return $meta;
    }

    /**
     * A poll the network returned, reduced to its stored result; null when the
     * post carries no poll.
     *
     * @return array<string, mixed>|null
     */
    public static function resultOf(mixed $poll): ?array
    {
        if (! is_array($poll)) {
            return null;
        }

        return static::result($poll);
    }

    /**
     * A poll the network returned, as `provider_metadata` keeps it; nothing
     * when the post carries no poll.
     *
     * @return array{poll?: array<string, mixed>}
     */
    public static function metadataFrom(mixed $poll): array
    {
        $result = static::resultOf($poll);

        if (blank($result)) {
            return [];
        }

        return ['poll' => $result];
    }

    /**
     * The poll rules of a network, or null when it has no polls.
     *
     * @return class-string<self>|null
     */
    public static function for(?Platform $platform): ?string
    {
        return match ($platform) {
            Platform::X => XPoll::class,
            Platform::Mastodon => MastodonPoll::class,
            Platform::Threads => ThreadsPoll::class,
            Platform::LinkedIn, Platform::LinkedInPage, Platform::TikTok, Platform::YouTube,
            Platform::Facebook, Platform::Instagram, Platform::InstagramFacebook, Platform::Pinterest,
            Platform::Bluesky, Platform::Telegram, Platform::Discord, Platform::GoogleBusiness, null => null,
        };
    }

    /**
     * The limits the composer reads from `Platform::publishConfig()`, so they are never copied into TypeScript.
     *
     * @return array{minOptions: int, maxOptions: int, optionMaxLength: int, minDuration: ?int, maxDuration: ?int, defaultDuration: ?int, questionMaxLength: ?int, multipleChoice: bool, hideTotals: bool}
     */
    public static function limits(): array
    {
        return [
            'minOptions' => static::MIN_OPTIONS,
            'maxOptions' => static::MAX_OPTIONS,
            'optionMaxLength' => static::OPTION_MAX_LENGTH,
            'minDuration' => static::MIN_DURATION,
            'maxDuration' => static::MAX_DURATION,
            'defaultDuration' => static::DEFAULT_DURATION,
            'questionMaxLength' => static::QUESTION_MAX_LENGTH,
            'multipleChoice' => static::MULTIPLE_CHOICE,
            'hideTotals' => static::HIDE_TOTALS,
        ];
    }

    /** Whether the author picks how long voting stays open. */
    protected static function hasDuration(): bool
    {
        return filled(static::MAX_DURATION);
    }

    /**
     * The poll's choices, trimmed, without blanks.
     *
     * @return list<string>
     */
    protected static function options(?array $meta): array
    {
        return self::choices(data_get($meta, 'poll.options'));
    }

    /**
     * A list of choices, trimmed, without blanks.
     *
     * @return list<string>
     */
    protected static function choices(mixed $options): array
    {
        return Collection::wrap($options)
            ->filter(fn (mixed $option): bool => is_string($option))
            ->map(fn (string $option): string => Str::trim($option))
            ->filter(fn (string $option): bool => filled($option))
            ->values()
            ->all();
    }

    /**
     * A poll in the shape every network's poll has, before the network is
     * known. Each choice keeps its position, blank ones included, so an error
     * on a choice is reported on the field that was sent; `forNetwork()` drops
     * the blanks and keeps what the network takes when the poll is stored.
     *
     * @param  array<string, mixed>  $poll
     * @return array{options: list<string>, duration_minutes?: int, multiple?: bool, hide_totals?: bool}
     */
    public static function normalize(array $poll): array
    {
        return Arr::whereNotNull([
            'options' => Collection::wrap(data_get($poll, 'options'))->map(self::submittedChoice(...))->values()->all(),
            'duration_minutes' => self::minutes(data_get($poll, 'duration_minutes')),
            'multiple' => self::flag(data_get($poll, 'multiple')),
            'hide_totals' => self::flag(data_get($poll, 'hide_totals')),
        ]);
    }

    /**
     * A poll with only what this network takes: its length where the author
     * picks one, and the multiple choice and hidden totals switches where the
     * network has them.
     *
     * @param  array<string, mixed>  $poll
     * @return array<string, mixed>
     */
    public static function forNetwork(array $poll): array
    {
        $normalized = [...self::normalize($poll), 'options' => self::choices(data_get($poll, 'options'))];

        if (! static::hasDuration()) {
            unset($normalized['duration_minutes']);
        }

        if (! static::MULTIPLE_CHOICE) {
            unset($normalized['multiple']);
        }

        if (! static::HIDE_TOTALS) {
            unset($normalized['hide_totals']);
        }

        return $normalized;
    }

    /**
     * The poll as the API and MCP preview show it: its choices, and its
     * length and switches on the networks that have them, with the defaults
     * the network publishes with.
     *
     * @return array{options: list<string>, duration_minutes?: int, multiple?: bool, hide_totals?: bool}
     */
    public static function preview(?array $meta): array
    {
        $preview = ['options' => self::options($meta)];

        if (static::hasDuration()) {
            $preview['duration_minutes'] = self::minutes(data_get($meta, 'poll.duration_minutes'), static::DEFAULT_DURATION);
        }

        if (static::MULTIPLE_CHOICE) {
            $preview['multiple'] = data_get($meta, 'poll.multiple') === true;
        }

        if (static::HIDE_TOTALS) {
            $preview['hide_totals'] = data_get($meta, 'poll.hide_totals') === true;
        }

        return $preview;
    }

    /**
     * A choice's length without the spaces around it, in UTF-16 units, so an
     * emoji outside the basic plane (🐧) counts two. X counts that way (it
     * refused a 25-character choice holding one); counting the same on every
     * network keeps each choice within the strictest reading.
     */
    protected static function choiceLength(string $choice): int
    {
        return intdiv(strlen(mb_convert_encoding(Str::trim($choice), 'UTF-16LE', 'UTF-8')), 2);
    }

    /**
     * Repeated choices, refused at every save.
     */
    protected static function duplicatesViolation(?array $meta): ?string
    {
        $duplicates = collect(self::options($meta))->map(fn (string $option): string => Str::lower($option))->duplicatesStrict();

        return when($duplicates->isNotEmpty(), fn (): string => trans('posts.form.poll.options_distinct'));
    }

    /**
     * Too many choices once blanks are dropped, refused at every save. Every
     * network takes four at most.
     */
    public static function optionsCountViolation(mixed $options): ?string
    {
        return when(
            count(self::choices($options)) > self::MAX_OPTIONS,
            fn (): string => trans('posts.form.poll.options_max', ['max' => self::MAX_OPTIONS]),
        );
    }

    /**
     * A choice longer than the network allows once trimmed, refused at every save.
     */
    protected static function choiceLengthViolation(mixed $choice): ?string
    {
        $tooLong = is_string($choice) && self::choiceLength($choice) > static::OPTION_MAX_LENGTH;

        return when($tooLong, fn (): string => trans('posts.form.poll.choice_max', ['max' => static::OPTION_MAX_LENGTH]));
    }

    /**
     * A length outside the network's range, refused at every save. A network
     * that sets the length itself ignores it (`forNetwork()` drops it).
     */
    protected static function durationViolation(?array $meta): ?string
    {
        $minutes = data_get($meta, 'poll.duration_minutes');

        if (! static::hasDuration() || ! is_numeric($minutes)) {
            return null;
        }

        $minutes = (int) $minutes;
        $outOfRange = $minutes < static::MIN_DURATION || $minutes > static::MAX_DURATION;

        return when($outOfRange, fn (): string => static::durationRangeMessage());
    }

    /**
     * What no poll of this network may store, even as a draft, keyed by its
     * field under `$prefix` (`thread_replies.0.` for a reply): repeated
     * choices, a choice too long and a length out of range.
     *
     * @return array<string, string>
     */
    public static function formatErrors(?array $meta, string $prefix = ''): array
    {
        $errors = [];
        $duplicates = self::duplicatesViolation($meta);

        if (filled($duplicates)) {
            $errors["{$prefix}poll.options"] = $duplicates;
        }

        foreach (Collection::wrap(data_get($meta, 'poll.options')) as $index => $choice) {
            $tooLong = static::choiceLengthViolation($choice);

            if (filled($tooLong)) {
                $errors["{$prefix}poll.options.{$index}"] = $tooLong;
            }
        }

        $duration = static::durationViolation($meta);

        if (filled($duration)) {
            $errors["{$prefix}poll.duration_minutes"] = $duration;
        }

        return $errors;
    }

    /**
     * "Add at least 2 choices", with the minimum every network shares.
     */
    public static function optionsRequiredMessage(): string
    {
        return trans('posts.form.poll.options_required', ['min' => self::MIN_OPTIONS]);
    }

    /**
     * "From 5 minutes to 7 days", with the range of this network.
     */
    public static function durationRangeMessage(): string
    {
        $maxDays = intdiv((int) static::MAX_DURATION, CarbonInterface::MINUTES_PER_HOUR * CarbonInterface::HOURS_PER_DAY);

        return trans('posts.form.poll.duration_range', ['min' => static::MIN_DURATION, 'max' => $maxDays]);
    }

    /**
     * What a poll needs before its post is scheduled or published.
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    protected static function requiredViolation(?array $meta): ?array
    {
        if (count(self::options($meta)) < self::MIN_OPTIONS) {
            return ['poll.options', self::optionsRequiredMessage()];
        }

        if (static::hasDuration() && blank(data_get($meta, 'poll.duration_minutes'))) {
            return ['poll.duration_minutes', trans('posts.form.poll.duration_required')];
        }

        return null;
    }

    /**
     * Why the poll cannot go yet: the first thing no save may store, else
     * what scheduling needs.
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function firstViolation(?array $meta): ?array
    {
        $errors = collect(static::formatErrors($meta));

        if ($errors->isNotEmpty()) {
            return [$errors->keys()->first(), $errors->first()];
        }

        return static::requiredViolation($meta);
    }

    /**
     * A poll's question is the post text: it cannot be scheduled or published
     * without one. It is measured as the network receives it, so editor markup
     * with no words (`<p></p>`) counts as no question.
     */
    public static function questionViolation(?string $content): ?string
    {
        $question = app(ContentSanitizer::class)->displayText((string) $content, static::platform());

        return when(blank($question), fn (): string => trans('posts.form.poll.question_required'));
    }

    /**
     * Why the network would refuse the poll at publish: no question, media on
     * it, or choices and a length that break its rules. Null when it can go.
     * `$meta` holds the poll under `poll`: the post's meta, or a reply itself.
     */
    public static function publishViolation(?string $question, bool $hasMedia, ?array $meta): ?string
    {
        $questionViolation = static::questionViolation($question);

        if (filled($questionViolation)) {
            return $questionViolation;
        }

        if ($hasMedia) {
            return trans('posts.errors.media_not_accepted');
        }

        return data_get(static::firstViolation($meta), 1);
    }

    protected static function minutes(mixed $value, ?int $default = null): ?int
    {
        return transform($value, fn (mixed $minutes): int => (int) $minutes, $default);
    }

    /**
     * A choice as sent, trimmed; anything that is not text counts as blank.
     */
    private static function submittedChoice(mixed $option): string
    {
        if (! is_string($option)) {
            return '';
        }

        return Str::trim($option);
    }

    private static function flag(mixed $value): ?bool
    {
        return transform($value, fn (mixed $flag): bool => filter_var($flag, FILTER_VALIDATE_BOOLEAN));
    }
}
