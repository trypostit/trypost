<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Support\LinkTlds;

class ContentSanitizer
{
    /**
     * Splits a candidate URL into the character that precedes it, prefix (scheme,
     * optional userinfo and `www.`), host, the host's last label and the path. The
     * boundary is consumed rather than looked behind so the same expression runs on
     * browsers without lookbehind, and it is put back untouched by the callback. An explicit scheme is proof on its
     * own that the token is a URL; a bare host only counts when its last label is a
     * delegated TLD, the single thing telling `acme.com` apart from `Node.js`.
     *
     * Hosts are matched as Unicode letters and digits so internationalised domains
     * (`café.com`, `пример.рф`) are recognised, and the lookbehind keeps a bare host
     * that follows an `@` out — that is an email address, which X does not link.
     */
    private const LINK_PATTERN = '~(^|[^\p{L}\p{N}\p{M}_@/.])((?:https?://(?:[^\s/@]+@)?)?(?:www\.)?)((?:[\p{L}\p{N}](?:[\p{L}\p{N}\p{M}-]*[\p{L}\p{N}\p{M}])?\.)+([\p{L}\p{N}\p{M}-]{2,63}))(?![\p{L}\p{N}\p{M}-])((?:/\S*)?)~iu';

    public function sanitize(string $content, Platform $platform): string
    {
        return match ($platform) {
            Platform::LinkedIn, Platform::LinkedInPage => $this->convertBoldAndStrip($content),
            Platform::Mastodon => $this->stripUnsafeHtml($content),
            Platform::Telegram => $this->toTelegramHtml($content),
            Platform::X => $this->defuseLinks($this->stripHtml($content)),
            default => $this->stripHtml($content),
        };
    }

    /**
     * The content as a reader will see it, which is what a character limit applies
     * to. Mirrors {@see self::sanitize()} arm for arm, because only two of them
     * leave markup behind:
     *
     * - Telegram is handed HTML with entities escaped for `parse_mode=HTML`, so its
     *   tags and entities both resolve away — `&amp;` renders as one character.
     * - Mastodon keeps an HTML subset but its sanitizer already decoded entities, so
     *   only the tags come off. Decoding again would eat a literal `&amp;` the user
     *   typed and undercount the post.
     * - Everything else, X included, is already plain text: the defused form is
     *   literally what gets posted, so it counts as-is.
     */
    public function displayText(string $content, Platform $platform): string
    {
        $sanitized = $this->sanitize($content, $platform);

        return match ($platform) {
            Platform::Telegram => html_entity_decode(strip_tags($sanitized), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            Platform::Mastodon => strip_tags($sanitized),
            default => $sanitized,
        };
    }

    /**
     * Rewrites every URL into a non-clickable form (`example.com` →
     * `example(.)com`), dropping the scheme and any `www.` prefix and breaking
     * every dot of the host — leaving one intact dot would still leave a
     * resolvable domain for X to detect.
     *
     * X bills a post carrying a link at a much higher rate than a plain post, and
     * its algorithm demotes link posts, so neither side of that wants the raw URL.
     * Off by default; opt in with `X_DEFUSE_LINKS`.
     */
    private function defuseLinks(string $content): string
    {
        if (! config('trypost.platforms.x.defuse_links')) {
            return $content;
        }

        $defused = preg_replace_callback(
            self::LINK_PATTERN,
            function (array $matches): string {
                [$whole, $boundary, $prefix, $host, $tld] = $matches;
                $path = $matches[5] ?? '';

                if ($prefix === '' && ! LinkTlds::has($tld)) {
                    return $whole;
                }

                return $boundary.str_replace('.', '(.)', $host).$path;
            },
            $content,
        );

        return $defused ?? $content;
    }

    /**
     * Telegram's `parse_mode=HTML` accepts a small tag allowlist and rejects
     * the rest; bare ampersands must be escaped or the parser errors.
     */
    private function toTelegramHtml(string $content): string
    {
        // Block elements → newlines (Telegram HTML has no <p>/<br>/<li>).
        $content = preg_replace('/<p[^>]*>/i', '', $content);
        $content = str_replace('</p>', "\n", $content);
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);
        $content = preg_replace('/<li[^>]*>/i', '- ', $content);
        $content = str_replace('</li>', "\n", $content);

        // Normalize to Telegram's tag names, then keep only its allowlist.
        $content = preg_replace(['/<(\/?)strong>/i', '/<(\/?)em>/i'], ['<$1b>', '<$1i>'], $content);
        $content = strip_tags($content, ['b', 'i', 'u', 's', 'a', 'code', 'pre']);

        // Telegram requires every <a> to carry an href; drop bare anchors so the parser doesn't reject the whole message.
        $content = preg_replace('/<a(?![^>]*\shref=)[^>]*>(.*?)<\/a>/is', '$1', $content);

        // Escape bare ampersands while leaving existing entities intact.
        $content = preg_replace('/&(?!(?:amp|lt|gt|quot|#\d+);)/', '&amp;', $content);

        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }

    private function stripHtml(string $content): string
    {
        // Convert <p> tags to newlines
        $content = preg_replace('/<p[^>]*>/i', '', $content);
        $content = str_replace('</p>', "\n", $content);

        // Convert <br> to newlines
        $content = preg_replace('/<br\s*\/?>/i', "\n", $content);

        // Convert list items to dash prefix
        $content = preg_replace('/<li[^>]*>/i', '- ', $content);
        $content = str_replace('</li>', "\n", $content);

        // Strip remaining HTML tags
        $content = strip_tags($content);

        // Decode HTML entities
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Clean up excessive newlines (max 2 consecutive)
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }

    private function stripUnsafeHtml(string $content): string
    {
        // Mastodon accepts a subset of HTML: p, strong, em, a, br, span
        $content = strip_tags($content, ['p', 'strong', 'em', 'b', 'i', 'a', 'br', 'span']);

        // Decode HTML entities that aren't part of allowed tags
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($content);
    }

    private function convertBoldAndStrip(string $content): string
    {
        // Convert <strong>/<b> to Unicode bold characters for LinkedIn
        $content = preg_replace_callback(
            '/<(?:strong|b)>(.*?)<\/(?:strong|b)>/si',
            fn ($matches) => $this->toUnicodeBold($matches[1]),
            $content
        );

        // Convert <u> to Unicode underline
        $content = preg_replace_callback(
            '/<u>(.*?)<\/u>/si',
            fn ($matches) => $this->toUnicodeUnderline($matches[1]),
            $content
        );

        return $this->stripHtml($content);
    }

    private function toUnicodeBold(string $text): string
    {
        $map = [
            'a' => '𝗮', 'b' => '𝗯', 'c' => '𝗰', 'd' => '𝗱', 'e' => '𝗲',
            'f' => '𝗳', 'g' => '𝗴', 'h' => '𝗵', 'i' => '𝗶', 'j' => '𝗷',
            'k' => '𝗸', 'l' => '𝗹', 'm' => '𝗺', 'n' => '𝗻', 'o' => '𝗼',
            'p' => '𝗽', 'q' => '𝗾', 'r' => '𝗿', 's' => '𝘀', 't' => '𝘁',
            'u' => '𝘂', 'v' => '𝘃', 'w' => '𝘄', 'x' => '𝘅', 'y' => '𝘆', 'z' => '𝘇',
            'A' => '𝗔', 'B' => '𝗕', 'C' => '𝗖', 'D' => '𝗗', 'E' => '𝗘',
            'F' => '𝗙', 'G' => '𝗚', 'H' => '𝗛', 'I' => '𝗜', 'J' => '𝗝',
            'K' => '𝗞', 'L' => '𝗟', 'M' => '𝗠', 'N' => '𝗡', 'O' => '𝗢',
            'P' => '𝗣', 'Q' => '𝗤', 'R' => '𝗥', 'S' => '𝗦', 'T' => '𝗧',
            'U' => '𝗨', 'V' => '𝗩', 'W' => '𝗪', 'X' => '𝗫', 'Y' => '𝗬', 'Z' => '𝗭',
            '0' => '𝟬', '1' => '𝟭', '2' => '𝟮', '3' => '𝟯', '4' => '𝟰',
            '5' => '𝟱', '6' => '𝟲', '7' => '𝟳', '8' => '𝟴', '9' => '𝟵',
        ];

        return strtr($text, $map);
    }

    private function toUnicodeUnderline(string $text): string
    {
        // Unicode combining underline character
        return implode('', array_map(
            fn ($char) => $char."\u{0332}",
            mb_str_split($text)
        ));
    }
}
