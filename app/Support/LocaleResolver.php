<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\User\Locale;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The only place that decides a request's UI locale.
 *
 * The two guest steps before `Accept-Language` serve the auth language
 * switcher: the submitted `locale` keeps backend validation messages in the
 * language on screen, and the flashed old input keeps them there when the form
 * re-renders after a failed submit.
 */
class LocaleResolver
{
    public static function resolve(Request $request): Locale
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->locale ?? Locale::DEFAULT;
        }

        return self::fromInput($request)
            ?? self::fromOldInput($request)
            ?? Locale::fromAcceptLanguage($request->header('Accept-Language'))
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
