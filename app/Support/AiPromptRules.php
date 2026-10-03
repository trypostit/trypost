<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared limits for prompts sent to the post assistant.
 */
final class AiPromptRules
{
    /**
     * Maximum prompt length (characters); mirrored by the frontend counter.
     */
    public const int PROMPT_MAX_LENGTH = 10000;

    public const int PROMPT_MIN_WORDS = 4;

    /**
     * Counts whitespace-separated words, with each Han, Hiragana or Katakana
     * character counted as one word since those scripts do not use spaces.
     */
    public static function wordCount(string $text): int
    {
        $pattern = '/[\p{Han}\p{Hiragana}\p{Katakana}]/u';
        $ideographs = preg_match_all($pattern, $text);
        $rest = preg_split('/\s+/u', trim((string) preg_replace($pattern, ' ', $text)), -1, PREG_SPLIT_NO_EMPTY);

        return $ideographs + count($rest);
    }
}
