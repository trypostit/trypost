<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Media\Source;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for inline post `media` validation, shared by the post
 * create/update flows. The web sends already-hosted media (id + path) and tracks
 * its source; the public REST API may send a bare external `url` we download and
 * host, so the id/path/url rules differ by contract.
 */
class PostMediaRules
{
    /**
     * Maximum stored length (characters) for a media item's alt text. Publishers
     * truncate further to each platform's own cap via Platform::altTextMaxLength().
     */
    public const ALT_TEXT_MAX_LENGTH = 2000;

    /**
     * @param  bool  $hosted  true (web): items must already be hosted (id + path
     *                        required); false (API): a bare external `url` is
     *                        accepted (and downloaded).
     * @return array<string, mixed>
     */
    public static function rules(bool $hosted): array
    {
        return [
            'media' => ['sometimes', 'array'],
            'media.*.id' => $hosted ? ['required', 'string'] : ['sometimes', 'nullable', 'string'],
            'media.*.path' => $hosted ? ['required', 'string', 'max:500'] : ['sometimes', 'nullable', 'string', 'max:500'],
            'media.*.url' => $hosted
                ? ['required', 'string', 'max:2048']
                : ['required', 'string', 'max:2048', 'url:http,https'],
            'media.*.type' => ['sometimes', 'nullable', 'string', 'max:32'],
            'media.*.mime_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'media.*.original_filename' => ['sometimes', 'nullable', 'string', 'max:500'],
            'media.*.size' => ['sometimes', 'nullable', 'integer'],
            'media.*.meta' => ['sometimes', 'nullable', 'array', static function (string $attribute, mixed $value, Closure $fail): void {
                $altText = data_get($value, 'alt_text');

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
            }],
            'media.*.source' => ['sometimes', 'nullable', 'string', Rule::in(array_column(Source::cases(), 'value'))],
            'media.*.source_meta' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
