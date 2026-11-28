<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Enums\User\Locale;
use App\Models\Offer;
use Illuminate\Support\Facades\Lang;

/**
 * Whether an offer's copy is in `lang/<locale>/offers.php`, where it lives
 * (a deploy adds it): the checkout copy every offer needs, and the banner's
 * when the offer shows one.
 */
final class OfferCopy
{
    /** Copy every offer needs. */
    private const array REQUIRED = ['price', 'period', 'checkout_button'];

    /** Copy an offer that shows its banner needs too. */
    private const array BANNER = ['name', 'headline'];

    /**
     * The locales missing some of the offer's copy.
     *
     * @return list<string>
     */
    public static function missingLocales(Offer $offer): array
    {
        $key = $offer->langKey();
        $needed = self::REQUIRED;

        if ($offer->show_banner) {
            $needed = [...$needed, ...self::BANNER];
        }

        return collect(Locale::cases())
            ->filter(fn (Locale $locale): bool => collect($needed)
                ->contains(fn (string $field): bool => ! Lang::has("offers.{$key}.{$field}", $locale->value, false)))
            ->map(fn (Locale $locale): string => $locale->value)
            ->values()
            ->all();
    }
}
