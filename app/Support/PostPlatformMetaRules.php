<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostPlatform\AspectRatio;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Rules\DiscordMentionsMeta;
use App\Rules\InstagramCollaboratorsMeta;
use App\Support\Social\InstagramCollaborators;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * Single source of truth for per-platform `PostPlatform.meta` validation and
 * persist-time shaping, shared by the web, public REST API, and MCP post
 * create/update flows so every entry point accepts the same per-platform
 * settings and enforces the same required-on-publish rules.
 *
 * Persist-time `normalize()` / `merge()` dispatch on the platform enum so
 * Instagram collaborator shaping never rewrites another network's meta.
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
            'platforms.*.meta.collaborators' => ['sometimes', 'nullable', 'bail', 'array', 'max:50', new InstagramCollaboratorsMeta],

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

            // Discord
            'platforms.*.meta.channel_id' => ['sometimes', 'nullable', 'string'],
            'platforms.*.meta.channel_name' => ['sometimes', 'nullable', 'string'],
            'platforms.*.meta.mentions' => ['sometimes', 'nullable', 'array', 'max:50', new DiscordMentionsMeta],
            'platforms.*.meta.mentions.*.token' => ['sometimes', 'nullable', 'string', 'max:100'],
            'platforms.*.meta.mentions.*.label' => ['sometimes', 'nullable', 'string', 'max:100'],
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
            'platforms.*.meta.collaborators' => __('posts.form.instagram.collaborators'),
        ];
    }

    /**
     * Shape incoming meta for one platform before it is stored. Only the matching
     * network's keys are rewritten; every other key (and every other network)
     * passes through unchanged.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function normalize(?Platform $platform, array $meta, ?string $ownUsername = null): array
    {
        return match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook => InstagramCollaborators::applyToMeta($meta, $ownUsername),
            default => $meta,
        };
    }

    /**
     * Merge incoming meta onto stored meta after platform-specific normalize.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  array<string, mixed>|null  $incoming
     * @param  ContentType|string|null  $contentType
     * @return array<string, mixed>
     */
    public static function merge(?Platform $platform, ?array $existing, ?array $incoming, mixed $contentType = null, ?string $ownUsername = null): array
    {
        $incoming = $incoming ?? [];
        $isInstagram = in_array($platform, [Platform::Instagram, Platform::InstagramFacebook], true);

        if ($isInstagram && self::dropsCollaborators($contentType)) {
            $incoming['collaborators'] = [];
        }

        $merged = array_filter(
            array_merge($existing ?? [], self::normalize($platform, $incoming, $ownUsername)),
            fn (mixed $value): bool => $value !== null,
        );

        if ($isInstagram) {
            unset($merged['collaborators_with']);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>|null  $incoming
     * @return array<string, mixed>
     */
    public static function mergeFrom(PostPlatform $postPlatform, ?array $incoming, mixed $contentType = null): array
    {
        return self::merge(
            self::platformOf($postPlatform),
            $postPlatform->meta,
            $incoming,
            $contentType ?? $postPlatform->content_type,
            $postPlatform->socialAccount?->username,
        );
    }

    /** True for Instagram Stories so persist and validation drop the key together. */
    public static function dropsCollaborators(mixed $contentType): bool
    {
        $contentType = $contentType instanceof ContentType
            ? $contentType
            : ContentType::tryFrom(is_string($contentType) ? $contentType : '');

        return $contentType?->platform() === Platform::Instagram && ! $contentType->supportsCollaborators();
    }

    /**
     * Connected account for a `platforms.{i}.meta.*` field. Self-checks only —
     * use platformForAttribute() to decide whether a rule applies.
     *
     * @param  array<string, mixed>  $data
     */
    public static function accountForAttribute(array $data, string $attribute): ?SocialAccount
    {
        return self::postPlatformFor($data, $attribute)?->socialAccount
            ?? self::findSocialAccount(self::rowValue($data, $attribute, 'social_account_id'));
    }

    /**
     * Network for a `platforms.{i}.meta.*` field. Disconnected rows use the
     * stored platform; a stale `id` falls back to `social_account_id`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function platformForAttribute(array $data, string $attribute): ?Platform
    {
        $postPlatform = self::postPlatformFor($data, $attribute);

        return $postPlatform !== null
            ? self::platformOf($postPlatform)
            : self::findSocialAccount(self::rowValue($data, $attribute, 'social_account_id'))?->platform;
    }

    /**
     * Content type the row will be saved as (submitted, else stored).
     *
     * @param  array<string, mixed>  $data
     */
    public static function contentTypeForAttribute(array $data, string $attribute): mixed
    {
        return self::rowValue($data, $attribute, 'content_type')
            ?? self::postPlatformFor($data, $attribute)?->content_type;
    }

    /** @param  array<string, mixed>  $data */
    private static function rowValue(array $data, string $attribute, string $key): mixed
    {
        return data_get($data, Str::before($attribute, '.meta.').".{$key}");
    }

    /** @param  array<string, mixed>  $data */
    private static function postPlatformFor(array $data, string $attribute): ?PostPlatform
    {
        return self::findPostPlatform(self::rowValue($data, $attribute, 'id'));
    }

    /** Memoized so every meta rule validating the same row shares one read. */
    private static function findPostPlatform(mixed $id): ?PostPlatform
    {
        return is_string($id) && Str::isUuid($id)
            ? once(fn (): ?PostPlatform => PostPlatform::query()->with('socialAccount')->find($id))
            : null;
    }

    private static function findSocialAccount(mixed $id): ?SocialAccount
    {
        return is_string($id) && Str::isUuid($id)
            ? once(fn (): ?SocialAccount => SocialAccount::query()->find($id))
            : null;
    }

    /**
     * Prefer the connected account's network over the denormalized
     * `post_platforms.platform` column (stale copies / factory defaults).
     */
    public static function platformOf(PostPlatform $postPlatform): Platform
    {
        return $postPlatform->socialAccount?->platform ?? $postPlatform->platform;
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

        foreach ($post->postPlatforms()->enabled()->with('socialAccount')->get()->values() as $index => $postPlatform) {
            $violation = self::requiredMetaViolation(self::platformOf($postPlatform), $postPlatform->meta);

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
