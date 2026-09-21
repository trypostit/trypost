<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GoogleBusiness\CtaAction;
use App\Enums\GoogleBusiness\TopicType;
use App\Enums\PostPlatform\AspectRatio;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Models\Post;
use Illuminate\Support\Str;
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
            'platforms.*.meta.privacy_level' => ['sometimes', 'nullable', 'string', Rule::enum(PrivacyLevel::class)],
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

            // Google Business Profile
            'platforms.*.meta.topic_type' => ['sometimes', 'nullable', 'string', Rule::enum(TopicType::class)],
            'platforms.*.meta.call_to_action' => ['sometimes', 'nullable', 'array'],
            'platforms.*.meta.call_to_action.action_type' => ['sometimes', 'nullable', 'string', Rule::enum(CtaAction::class)],
            'platforms.*.meta.call_to_action.url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'platforms.*.meta.event' => ['sometimes', 'nullable', 'array'],
            'platforms.*.meta.event.title' => ['sometimes', 'nullable', 'string', 'max:'.TopicType::TITLE_MAX_LENGTH],
            'platforms.*.meta.event.start_date' => ['sometimes', 'nullable', 'date'],
            'platforms.*.meta.event.end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:platforms.*.meta.event.start_date'],
            'platforms.*.meta.event.start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'platforms.*.meta.event.end_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'platforms.*.meta.offer' => ['sometimes', 'nullable', 'array'],
            'platforms.*.meta.offer.coupon_code' => ['sometimes', 'nullable', 'string'],
            'platforms.*.meta.offer.redeem_online_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2048'],
            'platforms.*.meta.offer.terms_conditions' => ['sometimes', 'nullable', 'string', 'max:5000'],
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
            'platforms.*.meta.event.end_date.after_or_equal' => __('posts.form.google_business.event_end_date_before_start'),
            'platforms.*.meta.event.title.max' => __('posts.form.google_business.title_max'),
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
            'platforms.*.meta.event.title' => __('posts.form.google_business.event_title'),
            'platforms.*.meta.call_to_action.url' => __('posts.form.google_business.cta_url'),
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
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The missing required meta field for a platform about to publish, or null when
     * nothing is missing. Single source of "what each platform requires to publish".
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function requiredMetaViolation(?Platform $platform, mixed $meta): ?array
    {
        $topicType = TopicType::fromMeta(data_get($meta, 'topic_type'));
        $ctaAction = CtaAction::fromMeta(data_get($meta, 'call_to_action.action_type'));
        $needsGoogleBusinessEvent = $platform === Platform::GoogleBusiness && $topicType->requiresEvent();

        return match (true) {
            $platform === Platform::TikTok => self::tiktokPrivacyViolation($meta),
            $platform === Platform::Pinterest && blank(data_get($meta, 'board_id')) => ['board_id', trans('posts.form.pinterest.board_required')],
            $platform === Platform::Discord && blank(data_get($meta, 'channel_id')) => ['channel_id', trans('posts.form.discord.channel_required')],
            $needsGoogleBusinessEvent
                && blank(data_get($meta, 'event.title')) => [
                    'event.title',
                    trans($topicType === TopicType::Offer
                        ? 'posts.form.google_business.offer_title_required'
                        : 'posts.form.google_business.event_title_required'),
                ],
            $needsGoogleBusinessEvent
                && self::googleBusinessEventTitleExceedsLimit($meta) => [
                    'event.title',
                    trans('posts.form.google_business.title_max'),
                ],
            $needsGoogleBusinessEvent
                && blank(data_get($meta, 'event.start_date')) => ['event.start_date', trans('posts.form.google_business.event_start_date_required')],
            $needsGoogleBusinessEvent
                && blank(data_get($meta, 'event.end_date')) => ['event.end_date', trans('posts.form.google_business.event_end_date_required')],
            $needsGoogleBusinessEvent
                && self::googleBusinessEventEndsBeforeStart($meta) => self::googleBusinessEventRangeViolation($meta),
            $platform === Platform::GoogleBusiness
                && $topicType->allowsCallToAction()
                && $ctaAction->requiresUrl()
                && blank(data_get($meta, 'call_to_action.url')) => ['call_to_action.url', trans('posts.form.google_business.cta_url_required')],
            default => null,
        };
    }

    /**
     * Event/offer titles over TITLE_MAX_LENGTH fail publish even when stored
     * outside rules() — MCP PublishPostTool only runs requiredMetaViolation().
     */
    private static function googleBusinessEventTitleExceedsLimit(mixed $meta): bool
    {
        $title = data_get($meta, 'event.title');

        return filled($title) && Str::length((string) $title) > TopicType::TITLE_MAX_LENGTH;
    }

    /**
     * Whether the Google Business event/offer schedule ends before it starts.
     * Same-day times count: 18:00 → 09:00 is invalid even when the dates match.
     */
    public static function googleBusinessEventEndsBeforeStart(mixed $meta): bool
    {
        $startDate = data_get($meta, 'event.start_date');
        $endDate = data_get($meta, 'event.end_date');

        if (blank($startDate) || blank($endDate)) {
            return false;
        }

        $startDate = (string) $startDate;
        $endDate = (string) $endDate;

        if ($endDate < $startDate) {
            return true;
        }

        if ($endDate !== $startDate) {
            return false;
        }

        $startTime = data_get($meta, 'event.start_time');
        $endTime = data_get($meta, 'event.end_time');

        return filled($startTime) && filled($endTime) && (string) $endTime < (string) $startTime;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function googleBusinessEventRangeViolation(mixed $meta): array
    {
        $sameDay = (string) data_get($meta, 'event.end_date') === (string) data_get($meta, 'event.start_date')
            && filled(data_get($meta, 'event.start_time'))
            && filled(data_get($meta, 'event.end_time'));

        return $sameDay
            ? ['event.end_time', trans('posts.form.google_business.event_end_time_before_start')]
            : ['event.end_date', trans('posts.form.google_business.event_end_date_before_start')];
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private static function tiktokPrivacyViolation(mixed $meta): ?array
    {
        $privacyLevel = PrivacyLevel::tryFrom((string) data_get($meta, 'privacy_level'));

        if ($privacyLevel === null) {
            return ['privacy_level', trans('posts.form.tiktok.privacy_required')];
        }

        if ($privacyLevel === PrivacyLevel::SelfOnly && data_get($meta, 'brand_content_toggle')) {
            return ['privacy_level', trans('posts.form.tiktok.privacy.private_disabled_branded')];
        }

        return null;
    }
}
