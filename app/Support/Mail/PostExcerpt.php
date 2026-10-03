<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Str;

/**
 * Plain-text excerpt of a post's editor HTML for emails: block tags and line
 * breaks become newlines, the rest of the markup is stripped and entities are
 * decoded, so the paragraphs of the post survive in a `pre-line` block.
 */
class PostExcerpt
{
    public static function from(?string $html, int $limit): string
    {
        $text = preg_replace('/<br\s*\/?>|<\/(p|div|li|h[1-6]|blockquote)>/i', "\n", (string) $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]*\n[ \t]*/", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", (string) $text);

        return Str::limit(trim((string) $text), $limit);
    }
}
