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
        $text = preg_replace('/<\/(div|h[1-6]|blockquote)>/i', "\n", (string) $html);
        $text = app(ContentSanitizer::class)->plainText((string) $text);
        $text = preg_replace("/[ \t]*\n[ \t]*/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return Str::limit(trim((string) $text), $limit);
    }
}
