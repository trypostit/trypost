<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\User\Locale;
use App\Models\User;
use Illuminate\Http\Request;

class LocaleResolver
{
    public static function resolve(Request $request): Locale
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->locale;
        }

        return self::fromInput($request)
            ?? self::fromOldInput($request)
            ?? Locale::DEFAULT;
    }

    private static function fromInput(Request $request): ?Locale
    {
        $locale = $request->input('locale');

        return is_string($locale) ? Locale::tryFrom($locale) : null;
    }

    private static function fromOldInput(Request $request): ?Locale
    {
        if (! $request->hasSession()) {
            return null;
        }

        $locale = $request->old('locale');

        return is_string($locale) ? Locale::tryFrom($locale) : null;
    }
}
