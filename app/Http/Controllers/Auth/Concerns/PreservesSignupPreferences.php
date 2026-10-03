<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait PreservesSignupPreferences
{
    private function storeSignupPreferences(Request $request): void
    {
        $request->session()->put('signup_preferences', collect($request->only(['timezone', 'week_starts_on', 'time_format']))
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->map(fn (string $value): string => Str::limit($value, 64, ''))
            ->all());
    }

    /**
     * @return array{timezone?: string, week_starts_on?: string, time_format?: string}
     */
    private function retrieveSignupPreferences(): array
    {
        return session()->pull('signup_preferences', []);
    }
}
