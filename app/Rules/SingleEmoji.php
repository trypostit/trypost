<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Exactly one emoji: a single grapheme that is a pictograph (with its skin
 * tone, variation selector or joined pictographs), a flag or a keycap. The
 * longest emoji takes a few code points; the cap keeps a pictograph followed
 * by stacked combining marks out of the column.
 */
class SingleEmoji implements ValidationRule
{
    public const MAX_CODE_POINTS = 16;

    private const PATTERN = '/^(?:\p{Regional_Indicator}{2}|[0-9#*]\x{FE0F}?\x{20E3}|\p{Extended_Pictographic})/u';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isEmoji = is_string($value)
            && Str::length($value) <= self::MAX_CODE_POINTS
            && grapheme_strlen($value) === 1
            && Str::isMatch(self::PATTERN, $value);

        if (! $isEmoji) {
            $fail('notes.errors.invalid_emoji')->translate();
        }
    }
}
