<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Services\Social\ContentSanitizer;
use Illuminate\Support\Str;

/**
 * Plain-text excerpt of a post for emails, read like the networks read it
 * (`ContentSanitizer::plainText()`): a typed `<` stays, the block tags of
 * older rich-editor posts become newlines, so the paragraphs survive in a
 * `pre-line` block.
 */
class PostExcerpt
{
    public static function from(?string $html, int $limit): string
    {
        return Str::limit(app(ContentSanitizer::class)->plainText((string) $html), $limit);
    }
}
