<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Exactly one emoji: a single grapheme that is pictographic, a flag or a keycap.
 */
class SingleEmoji implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $isEmoji = is_string($value)
            && grapheme_strlen($value) === 1
            && preg_match('/\p{Extended_Pictographic}|\p{Regional_Indicator}|\x{20E3}/u', $value) === 1;

        if (! $isEmoji) {
            $fail('notes.errors.invalid_emoji')->translate();
        }
    }
}
