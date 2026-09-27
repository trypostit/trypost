<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\YouTubeDescription;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class ValidYouTubeDescription implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = YouTubeDescription::violation($value);

        if ($key !== null) {
            $fail($key)->translate();
        }
    }
}
