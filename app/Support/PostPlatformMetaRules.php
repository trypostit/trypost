<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostPlatform\AspectRatio;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Single source of truth for per-platform `PostPlatform.meta` validation, shared
 * by the web, public REST API, and MCP post create/update flows so every entry
 * point accepts the same per-platform settings and enforces the same
 * required-on-publish rules.
 */
class PostPlatformMetaRules
{
    /**
     * TikTok content visibility options.
     *
     * @var array<int, string>
     */
    public const TIKTOK_PRIVACY_LEVELS = [
        'PUBLIC_TO_EVERYONE',
        'MUTUAL_FOLLOW_FRIENDS',
        'FOLLOWER_OF_CREATOR',
        'SELF_ONLY',
    ];

    public const GOOGLE_BUSINESS_PROFILE_CTA_TYPES = ['BOOK', 'ORDER', 'SHOP', 'LEARN_MORE', 'SIGN_UP', 'CALL'];

    public const GOOGLE_BUSINESS_PROFILE_RECURRENCE_PATTERNS = ['daily', 'weekly', 'monthly'];

    public const GOOGLE_BUSINESS_PROFILE_DAYS_OF_WEEK = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY', 'SATURDAY', 'SUNDAY'];

    public const GOOGLE_BUSINESS_PROFILE_WEEK_OCCURRENCES = ['FIRST', 'SECOND', 'THIRD', 'FOURTH', 'FIFTH', 'LAST'];

    /**
     * Resolve an update exactly as UpdatePost will: submitted rows become the
     * enabled set and merge their meta patches; omitted rows keep stored state.
     *
     * @param  array<int, mixed>|null  $submittedPlatforms
     * @return list<array{content_type: string, meta: array<string, mixed>}>
     */
    public static function effectivePayloadsForUpdate(Post $post, ?array $submittedPlatforms): array
    {
        $stored = $post->postPlatforms()->get()->keyBy('id');

        if ($submittedPlatforms === null) {
            return $stored->where('enabled', true)->map(fn ($postPlatform): array => [
                'content_type' => $postPlatform->content_type->value,
                'meta' => $postPlatform->meta ?? [],
            ])->values()->all();
        }

        return collect($submittedPlatforms)->map(function ($platform) use ($stored): array {
            $postPlatform = $stored->get(data_get($platform, 'id'));

            return [
                'content_type' => (string) (data_get($platform, 'content_type') ?? $postPlatform?->content_type?->value ?? ''),
                'meta' => array_filter(
                    array_merge($postPlatform?->meta ?? [], (array) data_get($platform, 'meta', [])),
                    fn (mixed $value): bool => $value !== null,
                ),
            ];
        })->values()->all();
    }

    /**
     * Validation rules for `platforms.*.meta` and all its per-platform sub-keys.
     * Spread into a FormRequest/MCP tool rule set as the complete meta contract.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'platforms.*.meta' => ['sometimes', 'nullable', 'array'],

            // Instagram / Facebook
            'platforms.*.meta.aspect_ratio' => ['sometimes', 'nullable', 'string', Rule::enum(AspectRatio::class)],

            // LinkedIn — title shown on a document (PDF carousel) post
            'platforms.*.meta.document_title' => ['sometimes', 'nullable', 'string', 'max:300'],

            // TikTok
            'platforms.*.meta.privacy_level' => ['sometimes', 'nullable', 'string', Rule::in(self::TIKTOK_PRIVACY_LEVELS)],
            'platforms.*.meta.auto_add_music' => ['sometimes', 'boolean'],
            'platforms.*.meta.allow_comments' => ['sometimes', 'boolean'],
            'platforms.*.meta.allow_duet' => ['sometimes', 'boolean'],
            'platforms.*.meta.allow_stitch' => ['sometimes', 'boolean'],
            'platforms.*.meta.is_aigc' => ['sometimes', 'boolean'],
            'platforms.*.meta.disclose' => ['sometimes', 'boolean'],
            'platforms.*.meta.brand_content_toggle' => ['sometimes', 'boolean'],
            'platforms.*.meta.brand_organic_toggle' => ['sometimes', 'boolean'],

            // Pinterest
            'platforms.*.meta.board_id' => ['sometimes', 'nullable', 'string'],
            'platforms.*.meta.title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'platforms.*.meta.link' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],

            // Google Business Profile local posts
            'platforms.*.meta.language_code' => ['sometimes', 'nullable', 'string', 'max:35'],
            'platforms.*.meta.cta_action_type' => ['sometimes', 'nullable', 'string', Rule::in(self::GOOGLE_BUSINESS_PROFILE_CTA_TYPES)],
            'platforms.*.meta.cta_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'platforms.*.meta.event_title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'platforms.*.meta.event_start_at' => ['sometimes', 'nullable', 'date'],
            'platforms.*.meta.event_end_at' => ['sometimes', 'nullable', 'date'],
            'platforms.*.meta.offer_coupon_code' => ['sometimes', 'nullable', 'string', 'max:255'],
            'platforms.*.meta.offer_redeem_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'platforms.*.meta.offer_terms' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'platforms.*.meta.alert_type' => ['sometimes', 'nullable', 'string', Rule::in(['COVID_19'])],
            'platforms.*.meta.recurrence_pattern' => ['sometimes', 'nullable', 'string', Rule::in(self::GOOGLE_BUSINESS_PROFILE_RECURRENCE_PATTERNS)],
            'platforms.*.meta.recurrence_series_end_at' => ['sometimes', 'nullable', 'date'],
            'platforms.*.meta.recurrence_days_of_week' => ['sometimes', 'nullable', 'array', 'min:1'],
            'platforms.*.meta.recurrence_days_of_week.*' => ['string', Rule::in(self::GOOGLE_BUSINESS_PROFILE_DAYS_OF_WEEK)],
            'platforms.*.meta.recurrence_day_of_month' => ['sometimes', 'nullable', 'integer', 'between:1,31'],
            'platforms.*.meta.recurrence_day_of_week_occurrence' => ['sometimes', 'nullable', 'string', Rule::in(self::GOOGLE_BUSINESS_PROFILE_WEEK_OCCURRENCES)],

            // Discord
            'platforms.*.meta.channel_id' => ['sometimes', 'nullable', 'string'],
            'platforms.*.meta.channel_name' => ['sometimes', 'nullable', 'string'],
            'platforms.*.meta.mentions' => ['sometimes', 'nullable', 'array'],
            'platforms.*.meta.mentions.*.token' => ['required', 'string'],
            'platforms.*.meta.mentions.*.label' => ['sometimes', 'nullable', 'string'],
            'platforms.*.meta.embeds' => ['sometimes', 'nullable', 'array', 'max:10'],
            'platforms.*.meta.embeds.*.title' => ['sometimes', 'nullable', 'string', 'max:256'],
            'platforms.*.meta.embeds.*.description' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'platforms.*.meta.embeds.*.url' => ['sometimes', 'nullable', 'url'],
            'platforms.*.meta.embeds.*.image' => ['sometimes', 'nullable', 'url'],
            'platforms.*.meta.embeds.*.color' => ['sometimes', 'nullable', 'string', 'regex:/^#?[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * Custom validation messages for meta fields shown in the UI.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'platforms.*.meta.link.url' => __('posts.form.pinterest.link_invalid'),
            'platforms.*.meta.link.max' => __('posts.form.pinterest.link_max'),
            'platforms.*.meta.title.max' => __('posts.form.pinterest.title_max'),
        ];
    }

    /**
     * Friendly attribute names so default messages never expose `platforms.0.meta.*`.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'platforms.*.meta.title' => __('posts.form.pinterest.title'),
            'platforms.*.meta.link' => __('posts.form.pinterest.link'),
        ];
    }

    /**
     * Adds validation errors for per-platform meta that becomes mandatory once a
     * post is published or scheduled (TikTok privacy, Pinterest board, Discord
     * channel), based on the submitted request platforms. The caller resolves each
     * platform row to its Platform enum, since that lookup differs between create
     * (by social account) and update (by post platform).
     *
     * @param  array<int, mixed>  $platforms
     * @param  callable(mixed, int): ?Platform  $resolvePlatform
     */
    public static function addRequiredOnPublishErrors(Validator $validator, array $platforms, callable $resolvePlatform): void
    {
        foreach ($platforms as $index => $platform) {
            $violation = self::requiredMetaViolation($resolvePlatform($platform, $index), data_get($platform, 'meta'));

            if ($violation !== null) {
                [$field, $message] = $violation;
                $validator->errors()->add("platforms.{$index}.meta.{$field}", $message);
            }
        }
    }

    /**
     * Asserts that every ENABLED platform already stored on a post has the meta it
     * needs to publish. Used by entry points that publish a post's stored state
     * without resubmitting platforms (e.g. the MCP publish tool), so a misconfigured
     * post fails fast with a clear message instead of only at publish time.
     *
     * @throws ValidationException
     */
    public static function assertStoredPostPublishable(Post $post): void
    {
        $errors = [];

        foreach ($post->postPlatforms()->enabled()->get()->values() as $index => $postPlatform) {
            $violation = self::requiredMetaViolation($postPlatform->platform, $postPlatform->meta);

            if ($violation !== null) {
                [$field, $message] = $violation;
                $errors["platforms.{$index}.meta.{$field}"] = $message;
            }

            $errors = [...$errors, ...self::googleBusinessProfileErrorsFor(
                $postPlatform->content_type,
                $postPlatform->meta ?? [],
                "platforms.{$index}.meta",
            )];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, mixed>  $platforms
     * @param  callable(mixed, int): ?ContentType  $resolveContentType
     */
    public static function addGoogleBusinessProfileErrors(Validator $validator, array $platforms, callable $resolveContentType): void
    {
        foreach ($platforms as $index => $platform) {
            foreach (self::googleBusinessProfileErrorsFor(
                $resolveContentType($platform, $index),
                (array) data_get($platform, 'meta', []),
                "platforms.{$index}.meta",
            ) as $field => $message) {
                $validator->errors()->add($field, $message);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $platforms
     * @param  callable(mixed, int): ?ContentType  $resolveContentType
     *
     * @throws ValidationException
     */
    public static function assertGoogleBusinessProfilePayloads(array $platforms, callable $resolveContentType): void
    {
        $errors = [];
        foreach ($platforms as $index => $platform) {
            $errors = [...$errors, ...self::googleBusinessProfileErrorsFor(
                $resolveContentType($platform, $index),
                (array) data_get($platform, 'meta', []),
                "platforms.{$index}.meta",
            )];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @param  array<int, mixed>  $platforms
     * @return array<string, string>
     */
    public static function googleBusinessProfileLocationErrorsForUpdate(Post $post, array $platforms): array
    {
        $targets = $post->postPlatforms()
            ->with('googleBusinessProfileLocation')
            ->whereIn('id', collect($platforms)->pluck('id')->filter())
            ->get()
            ->keyBy('id');
        $errors = [];

        foreach ($platforms as $index => $platform) {
            $target = $targets->get(data_get($platform, 'id'));
            if ($target?->platform !== Platform::GoogleBusinessProfile) {
                continue;
            }

            $location = $target->googleBusinessProfileLocation;
            if (! $location || ! $location->is_selected || $location->social_account_id !== $target->social_account_id) {
                $errors["platforms.{$index}.id"] = 'Choose a currently selected Google Business Profile location.';
            }
        }

        return $errors;
    }

    /** @param array<string, mixed> $meta
     * @return array<string, string>
     */
    private static function googleBusinessProfileErrorsFor(?ContentType $contentType, array $meta, string $prefix): array
    {
        $gbpTypes = [
            ContentType::GoogleBusinessProfileStandard,
            ContentType::GoogleBusinessProfileEvent,
            ContentType::GoogleBusinessProfileOffer,
            ContentType::GoogleBusinessProfileAlert,
        ];
        if (! in_array($contentType, $gbpTypes, true)) {
            return [];
        }

        $errors = [];
        if (filled(data_get($meta, 'cta_action_type'))
            && data_get($meta, 'cta_action_type') !== 'CALL'
            && blank(data_get($meta, 'cta_url'))) {
            $errors["{$prefix}.cta_url"] = 'A destination URL is required for this call to action.';
        }

        if (in_array($contentType, [ContentType::GoogleBusinessProfileEvent, ContentType::GoogleBusinessProfileOffer], true)) {
            foreach (['event_title' => 'Event title', 'event_start_at' => 'Event start', 'event_end_at' => 'Event end'] as $field => $label) {
                if (blank(data_get($meta, $field))) {
                    $errors["{$prefix}.{$field}"] = "{$label} is required for this Google Business Profile post type.";
                }
            }

            if (filled(data_get($meta, 'event_start_at')) && filled(data_get($meta, 'event_end_at'))
                && strtotime((string) data_get($meta, 'event_end_at')) <= strtotime((string) data_get($meta, 'event_start_at'))) {
                $errors["{$prefix}.event_end_at"] = 'Event end must be after event start.';
            }
        }

        if ($contentType === ContentType::GoogleBusinessProfileAlert && blank(data_get($meta, 'alert_type'))) {
            $errors["{$prefix}.alert_type"] = 'Alert type is required for a Google Business Profile alert.';
        }

        if (data_get($meta, 'recurrence_pattern') === 'weekly' && blank(data_get($meta, 'recurrence_days_of_week'))) {
            $errors["{$prefix}.recurrence_days_of_week"] = 'Choose at least one weekday for weekly recurrence.';
        }

        if (data_get($meta, 'recurrence_pattern') === 'monthly') {
            $monthlyOptions = collect([
                data_get($meta, 'recurrence_day_of_month'),
                data_get($meta, 'recurrence_day_of_week_occurrence'),
            ])->filter(fn (mixed $value): bool => filled($value))->count();
            if ($monthlyOptions !== 1) {
                $errors["{$prefix}.recurrence_day_of_month"] = 'Choose either a day of month or a weekday occurrence for monthly recurrence.';
            }
        }

        return $errors;
    }

    /**
     * The missing required meta field for a platform about to publish, or null when
     * nothing is missing. Single source of "what each platform requires to publish".
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    private static function requiredMetaViolation(?Platform $platform, mixed $meta): ?array
    {
        return match (true) {
            $platform === Platform::TikTok && blank(data_get($meta, 'privacy_level')) => ['privacy_level', trans('posts.form.tiktok.privacy_required')],
            $platform === Platform::Pinterest && blank(data_get($meta, 'board_id')) => ['board_id', trans('posts.form.pinterest.board_required')],
            $platform === Platform::Discord && blank(data_get($meta, 'channel_id')) => ['channel_id', trans('posts.form.discord.channel_required')],
            default => null,
        };
    }
}
