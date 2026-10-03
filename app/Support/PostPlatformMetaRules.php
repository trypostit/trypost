<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\GoogleBusiness\CtaAction;
use App\Enums\GoogleBusiness\TopicType;
use App\Enums\PostPlatform\AspectRatio;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Enums\YouTube\Category;
use App\Enums\YouTube\License;
use App\Enums\YouTube\PrivacyStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Rules\ValidYouTubeDescription;
use Closure;
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
            'platforms.*.meta.share_to_feed' => ['sometimes', 'boolean'],

            // Mastodon — content warning, counted against the post limit
            'platforms.*.meta.spoiler_text' => ['sometimes', 'nullable', 'string', 'max:500'],

            // Threads of posts (Bluesky, Mastodon) — per-network checks in ThreadReplies
            'platforms.*.meta.thread_replies' => ['sometimes', 'nullable', 'array', 'max:'.ThreadReplies::MAX_REPLIES],
            'platforms.*.meta.thread_replies.*' => ['nullable', 'string', 'max:'.Post::CONTENT_MAX_LENGTH],

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

            // YouTube
            'platforms.*.meta.description' => ['sometimes', 'nullable', 'string', new ValidYouTubeDescription],
            'platforms.*.meta.category_id' => ['sometimes', 'nullable', Rule::in(array_column(Category::cases(), 'value'))],
            'platforms.*.meta.privacy_status' => ['sometimes', 'nullable', 'string', Rule::enum(PrivacyStatus::class)],
            'platforms.*.meta.license' => ['sometimes', 'nullable', 'string', Rule::enum(License::class)],
            'platforms.*.meta.notify_subscribers' => ['sometimes', 'boolean'],
            'platforms.*.meta.embeddable' => ['sometimes', 'boolean'],
            'platforms.*.meta.made_for_kids' => ['sometimes', 'boolean'],

            // YouTube + Instagram — self-disclosed AI content
            'platforms.*.meta.is_ai_generated' => ['sometimes', 'boolean'],

            // Facebook, Bluesky, LinkedIn
            'platforms.*.meta.link_preview' => ['sometimes', 'boolean:strict'],

            // Threads
            'platforms.*.meta.topic_tag' => ['sometimes', 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if (! self::isThreadsTopicTag((string) $value)) {
                    $fail(__('posts.form.threads.topic_invalid'));
                }
            }],

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
     * The meta of each submitted platform entry, keyed by the given field.
     *
     * @param  array<int, mixed>  $platforms
     * @return array<string, array<string, mixed>>
     */
    public static function metaByKey(array $platforms, string $keyField): array
    {
        return collect($platforms)
            ->filter(fn (mixed $platform): bool => is_string(data_get($platform, $keyField)))
            ->mapWithKeys(fn (mixed $platform): array => [data_get($platform, $keyField) => (array) data_get($platform, 'meta', [])])
            ->all();
    }

    /**
     * The content type of each submitted platform entry, keyed by the given
     * field, falling back to the stored one when the entry omits it.
     *
     * @param  array<int, mixed>  $platforms
     * @param  array<string, string|null>  $stored
     * @return array<string, string|null>
     */
    public static function contentTypesByKey(array $platforms, string $keyField, array $stored = []): array
    {
        return collect($platforms)
            ->filter(fn (mixed $platform): bool => is_string(data_get($platform, $keyField)))
            ->mapWithKeys(fn (mixed $platform): array => [
                data_get($platform, $keyField) => data_get($platform, 'content_type', data_get($stored, data_get($platform, $keyField))),
            ])
            ->all();
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
            'platforms.*.meta.description' => __('posts.form.youtube.description'),
            'platforms.*.meta.link' => __('posts.form.pinterest.link'),
            'platforms.*.meta.event.title' => __('posts.form.google_business.event_title'),
            'platforms.*.meta.call_to_action.url' => __('posts.form.google_business.cta_url'),
        ];
    }

    /**
     * Plain-text description of every per-platform meta key, shown to MCP
     * clients on the create and update post tools.
     */
    public static function documentation(): string
    {
        return implode(' ', [
            'Per-platform metadata.',
            'Instagram/Facebook: aspect_ratio (1:1|4:5|16:9|original).',
            'TikTok: privacy_level PUBLIC_TO_EVERYONE|MUTUAL_FOLLOW_FRIENDS|FOLLOWER_OF_CREATOR|SELF_ONLY (required to publish) + flags (allow_comments, allow_duet, allow_stitch, disclose, brand_content_toggle, brand_organic_toggle, is_aigc, auto_add_music). SELF_ONLY cannot be combined with brand_content_toggle.',
            'Mastodon: spoiler_text (content warning, ≤500; counts toward the 500-character post limit).',
            'Pinterest: board_id (required to publish — call ListPinterestBoardsTool first), title (≤100), link (destination URL). Pin description comes from the post content.',
            'Discord: channel_id (required to publish — call ListDiscordChannelsTool first), mentions ([{token,label}]), embeds ([{title,description,url,image,color}]).',
            'YouTube Shorts: title (≤100, no < or >; omitted, it is derived from the first non-empty line of the content with < and > removed, cut to 100 characters), description (plain text, at most 5000 bytes; omit or null to use the content), category_id (YouTube category id: '.collect(Category::cases())->map(fn (Category $category): string => "{$category->value}=".__($category->labelKey(), [], 'en'))->implode(', ').'; default '.Category::DEFAULT->value.'), privacy_status (public|unlisted|private, default public), license (youtube|creativeCommon), notify_subscribers (default true), embeddable (default true), made_for_kids (default false), is_ai_generated (discloses altered or synthetic content).',
            'Instagram: is_ai_generated (feed, reels, carousels), share_to_feed (reels, default true).',
            'Threads: topic_tag (1-50 characters after a leading # is dropped, no . or &). Use content_type threads_ghost_post for a text-only post archived after 24 hours (no media, no links, no topic).',
            'Facebook, Bluesky, LinkedIn: link_preview (default true; false publishes a text post with a link without its preview card).',
            'Threads of posts: thread_replies (list of up to '.ThreadReplies::MAX_REPLIES.' plain-text replies published under the post as a thread) on Bluesky and Mastodon only; each reply must fit the network limit (Bluesky 300, Mastodon 500 including the content warning, which every reply repeats).',
        ]);
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
                $key = "platforms.{$index}.meta.{$field}";

                if (! $validator->errors()->has($key)) {
                    $validator->errors()->add($key, $message);
                }
            }
        }
    }

    /**
     * Asserts that every ENABLED platform already stored on a post has the meta it
     * needs to publish. Used by entry points that publish a post's stored state
     * without resubmitting platforms (e.g. the MCP publish tool), so a misconfigured
     * post fails fast with a clear message instead of only at publish time.
     *
     * @param  array<int, string>  $platformIds  Submitted order for indexed errors.
     *
     * @throws ValidationException
     */
    public static function assertStoredPostPublishable(Post $post, array $platformIds = []): void
    {
        $platforms = $post->postPlatforms()->enabled()->get()->values();

        if ($platformIds !== []) {
            $platformsById = $platforms->keyBy('id');
            $platforms = collect($platformIds)
                ->map(fn (string $id): ?PostPlatform => $platformsById->get($id))
                ->filter();
        }

        $errors = [];

        foreach ($platforms as $index => $postPlatform) {
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
     * Meta keys stored as given by any entry point, normalized to the shape
     * the composer and publishers read: the YouTube category id is a string.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function normalize(array $meta): array
    {
        if (is_int(data_get($meta, 'category_id'))) {
            $meta['category_id'] = (string) $meta['category_id'];
        }

        if (is_string(data_get($meta, 'topic_tag'))) {
            $meta['topic_tag'] = self::threadsTopicTag($meta['topic_tag']);
        }

        return $meta;
    }

    /**
     * A Threads topic as the API takes it: trimmed, without a leading #.
     */
    public static function threadsTopicTag(string $value): string
    {
        return ltrim(Str::trim($value), '#');
    }

    /**
     * Threads accepts 1-50 characters without . or &, judged after the leading # is dropped.
     */
    private static function isThreadsTopicTag(string $value): bool
    {
        $tag = self::threadsTopicTag($value);

        return $tag !== '' && mb_strlen($tag) <= 50 && preg_match('/[.&]/', $tag) !== 1;
    }

    /**
     * A meta value no post may store, even as a draft: a YouTube title with
     * < or >.
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function formatViolation(?Platform $platform, mixed $meta): ?array
    {
        return $platform === Platform::YouTube ? YouTubeMetadata::violation($meta) : null;
    }

    /**
     * The missing required meta field for a platform about to publish, or null when
     * nothing is missing. Single source of "what each platform requires to publish".
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function requiredMetaViolation(?Platform $platform, mixed $meta): ?array
    {
        $threadViolation = ThreadReplies::violation($platform, $meta);

        if ($threadViolation !== null) {
            return $threadViolation;
        }

        $topicType = TopicType::fromMeta(data_get($meta, 'topic_type'));
        $ctaAction = CtaAction::fromMeta(data_get($meta, 'call_to_action.action_type'));
        $needsGoogleBusinessEvent = $platform === Platform::GoogleBusiness && $topicType->requiresEvent();

        return match (true) {
            $platform === Platform::YouTube => self::youtubeDescriptionViolation($meta) ?? self::formatViolation($platform, $meta),
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
     * @return array{0: string, 1: string}|null
     */
    private static function youtubeDescriptionViolation(mixed $meta): ?array
    {
        $key = YouTubeDescription::violation(data_get($meta, 'description'));

        return $key === null ? null : ['description', __($key)];
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
