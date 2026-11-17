<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Media\CoverSource;
use App\Enums\Media\Source;
use Closure;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for inline post `media` validation, shared by the post
 * create/update flows. The web sends whole hosted items it renders (`hostedRules`);
 * the public REST API and MCP send a reference to one file (`rules`).
 */
class PostMediaRules
{
    /**
     * Maximum stored length (characters) for a media item's alt text. Publishers
     * truncate further to each platform's own cap via Platform::altTextMaxLength().
     */
    public const ALT_TEXT_MAX_LENGTH = 2000;

    /**
     * Instagram accepts at most 20 people tagged on one image.
     */
    public const USER_TAGS_MAX = 20;

    /**
     * Longest reference (upload token, URL or id) a video cover may give.
     */
    public const int COVER_REFERENCE_MAX_LENGTH = 2048;

    /**
     * Instagram usernames: letters, numbers, periods and underscores, up to 30.
     */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9._]{1,30}$/';

    /**
     * Rules for the public API and MCP: every item gives exactly one of an
     * `upload_token` (a file uploaded earlier), a `url` (downloaded once per
     * call) or an `id` (a media row of this workspace, copied). `$key` is the
     * attribute that holds the list, `media` or `destinations.*.media`.
     *
     * @return array<string, mixed>
     */
    public static function rules(string $key = 'media'): array
    {
        return [
            $key => ['sometimes', 'list'],
            "{$key}.*" => ['bail', 'array', static function (string $attribute, mixed $value, Closure $fail): void {
                $given = array_filter([
                    filled(data_get($value, 'upload_token')),
                    filled(data_get($value, 'url')),
                    filled(data_get($value, 'id')),
                ]);

                if (count($given) !== 1) {
                    $fail('posts.errors.media_item_shape')->translate();
                }
            }],
            "{$key}.*.upload_token" => ['sometimes', 'nullable', 'uuid'],
            "{$key}.*.url" => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https'],
            "{$key}.*.id" => ['sometimes', 'nullable', 'string'],
            "{$key}.*.alt" => ['sometimes', 'nullable', 'string', 'max:'.self::ALT_TEXT_MAX_LENGTH],
            "{$key}.*.meta" => self::metaRules(),
        ];
    }

    /**
     * Rules for the web composer, which renders its media and so sends whole
     * hosted items (id + path + url) and tracks their source.
     *
     * @return array<string, mixed>
     */
    public static function hostedRules(): array
    {
        return [
            'media' => ['sometimes', 'array'],
            'media.*.id' => ['required', 'string'],
            'media.*.path' => ['required', 'string', 'max:500'],
            'media.*.url' => ['required', 'string', 'max:2048'],
            'media.*.type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'media.*.mime_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'media.*.original_filename' => ['sometimes', 'nullable', 'string', 'max:500'],
            'media.*.size' => ['sometimes', 'nullable', 'integer'],
            'media.*.meta' => self::metaRules(),
            'media.*.source' => ['sometimes', 'nullable', 'string', Rule::in(array_column(Source::cases(), 'value'))],
            'media.*.source_meta' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    public static function metaRules(): array
    {
        return ['sometimes', 'nullable', 'array', static function (string $attribute, mixed $value, Closure $fail): void {
            self::validateAltText(data_get($value, 'alt_text'), $fail);
            self::validateUserTags(data_get($value, 'user_tags'), $fail);
            self::validateCoverOffset(data_get($value, 'cover_offset_ms'), data_get($value, 'duration'), $fail);
            self::validateCover(data_get($value, 'cover'), $fail);
            self::validateCoverSource(data_get($value, 'cover_source'), $fail);
        }];
    }

    private static function validateCoverSource(mixed $source, Closure $fail): void
    {
        if (blank($source)) {
            return;
        }

        if (blank(CoverSource::tryFrom((string) $source))) {
            $fail('validation.in')->translate(['attribute' => 'cover_source']);
        }
    }

    /**
     * The image uploaded as a video's cover: a reference to one file like a
     * media item (an `upload_token`, a `url` or an `id`; the web sends the
     * whole hosted item). Its type is checked once the file is resolved.
     */
    private static function validateCover(mixed $cover, Closure $fail): void
    {
        if (blank($cover)) {
            return;
        }

        if (! is_array($cover)) {
            $fail('posts.errors.cover_invalid')->translate();

            return;
        }

        $references = collect(Arr::only($cover, ['upload_token', 'url', 'id']))->filter(filled(...));

        if ($references->isEmpty()) {
            $fail('posts.errors.cover_invalid')->translate();

            return;
        }

        if ($references->contains(fn (mixed $reference): bool => ! is_string($reference))) {
            $fail('posts.errors.cover_invalid')->translate();

            return;
        }

        if ($references->contains(fn (string $reference): bool => Str::length($reference) > self::COVER_REFERENCE_MAX_LENGTH)) {
            $fail('posts.errors.cover_invalid')->translate();
        }
    }

    /**
     * The video frame picked as the cover, in milliseconds. It cannot pass the
     * end of the video when the item's measured duration (seconds) is known.
     */
    private static function validateCoverOffset(mixed $offset, mixed $duration, Closure $fail): void
    {
        if ($offset === null) {
            return;
        }

        $attribute = trans('posts.composer.media_editor.thumbnail_tab');

        if (is_bool($offset) || filter_var($offset, FILTER_VALIDATE_INT) === false) {
            $fail('validation.integer')->translate(['attribute' => $attribute]);

            return;
        }

        if ((int) $offset < 0) {
            $fail('posts.errors.cover_offset_min')->translate();

            return;
        }

        if (is_numeric($duration) && (int) $offset > (int) round((float) $duration * 1000)) {
            $fail('posts.errors.cover_offset_max')->translate(['max' => (int) round((float) $duration * 1000)]);
        }
    }

    private static function validateAltText(mixed $altText, Closure $fail): void
    {
        if ($altText === null) {
            return;
        }

        if (! is_string($altText)) {
            $fail('validation.string')->translate(['attribute' => trans('posts.edit.alt_text.label')]);

            return;
        }

        if (mb_strlen($altText) > self::ALT_TEXT_MAX_LENGTH) {
            $fail('validation.max.string')->translate(['attribute' => trans('posts.edit.alt_text.label'), 'max' => self::ALT_TEXT_MAX_LENGTH]);
        }
    }

    /**
     * Nested `media.*.meta.user_tags.*` rules would make `validated()` drop every
     * other meta key, so the tags are checked here instead.
     */
    private static function validateUserTags(mixed $tags, Closure $fail): void
    {
        if ($tags === null) {
            return;
        }

        $attribute = trans('posts.edit.user_tags.label');

        if (! is_array($tags) || ! array_is_list($tags)) {
            $fail('validation.list')->translate(['attribute' => $attribute]);

            return;
        }

        if (count($tags) > self::USER_TAGS_MAX) {
            $fail('validation.max.array')->translate(['attribute' => $attribute, 'max' => self::USER_TAGS_MAX]);

            return;
        }

        foreach ($tags as $tag) {
            $username = data_get($tag, 'username');
            $x = data_get($tag, 'x');
            $y = data_get($tag, 'y');

            if (! is_string($username) || preg_match(self::USERNAME_PATTERN, ltrim($username, '@')) !== 1) {
                $fail('validation.regex')->translate(['attribute' => $attribute]);

                return;
            }

            if (! is_numeric($x) || ! is_numeric($y) || $x < 0 || $x > 1 || $y < 0 || $y > 1) {
                $fail('validation.between.numeric')->translate(['attribute' => $attribute, 'min' => 0, 'max' => 1]);

                return;
            }
        }
    }
}
