<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Post;
use App\Services\Social\ContentSanitizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * Caps post content by the text a reader sees, the same text the per-network
 * limits and the composer counter measure, so editor markup never pushes a post
 * that fits every network over the cap. The raw value is still bounded by what
 * the `mediumText` column stores.
 */
class PostContentFitsMaxLength implements ValidationRule
{
    private const int STORAGE_MAX_BYTES = 16_777_215;

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        if (strlen($value) > self::STORAGE_MAX_BYTES
            || mb_strlen(app(ContentSanitizer::class)->plainText($value)) > Post::CONTENT_MAX_LENGTH) {
            $fail('validation.max.string')->translate(['max' => Post::CONTENT_MAX_LENGTH]);
        }
    }
}
