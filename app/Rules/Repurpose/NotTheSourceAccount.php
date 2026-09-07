<?php

declare(strict_types=1);

namespace App\Rules\Repurpose;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A repurpose copies what an account posts elsewhere. Pointing it back at that
 * same account would republish the video onto the profile it came from.
 */
class NotTheSourceAccount implements ValidationRule
{
    public function __construct(private readonly ?string $sourceAccountId) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->sourceAccountId !== null && (string) $value === $this->sourceAccountId) {
            $fail(__('repurposes.errors.destination_is_source'));
        }
    }
}
