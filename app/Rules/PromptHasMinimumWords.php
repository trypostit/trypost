<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\AiPromptRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class PromptHasMinimumWords implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || AiPromptRules::wordCount($value) >= AiPromptRules::PROMPT_MIN_WORDS) {
            return;
        }

        $fail(__('posts.composer.assistant_prompt_min_words', ['count' => AiPromptRules::PROMPT_MIN_WORDS]));
    }
}
