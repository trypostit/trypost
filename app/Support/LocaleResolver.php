<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\User\Locale;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves the UI locale for a request. The authenticated user's stored locale
 * is the source of truth; a guest is resolved from the request itself.
 *
 * The two guest steps before `Accept-Language` exist for the language picker on
 * the register page: the submitted `locale` keeps the backend validation
 * messages in the language the visitor picked, and the flashed old input keeps
 * the re-rendered form in it after a failed submit.
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
