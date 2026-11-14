<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use IntlTimeZone;

class Timezone
{
    public const DEFAULT = 'UTC';

    public static function normalize(?string $value): string
    {
        if (blank($value)) {
            return self::DEFAULT;
        }

        $canonical = once(fn (): Collection => collect(DateTimeZone::listIdentifiers())->flip());

        return collect([$value, IntlTimeZone::getIanaID($value)])
            ->first(fn (mixed $zone): bool => is_string($zone) && $canonical->has($zone), self::DEFAULT);
    }

    /**
     * A stored zone made safe to compute with: its canonical identifier, or the
     * zone itself when PHP knows it without one (Etc/GMT+10), so a zone PHP
     * understands never shifts to UTC; only one it does not know is replaced.
     */
    public static function usable(string $value): string
    {
        $known = once(fn (): Collection => collect(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))->flip());
        $normalized = self::normalize($value);

        return $normalized === self::DEFAULT && $known->has($value) ? $value : $normalized;
    }

    /**
     * @return list<array{value: string, label: string, offset: string}>
     */
    public static function options(): array
    {
        return collect(DateTimeZone::listIdentifiers())
            ->map(fn (string $identifier): array => self::option($identifier))
            ->all();
    }

    /**
     * A canonical time zone as the pickers show it: its city ("Sao Paulo") and
     * its current offset ("GMT-3").
     *
     * @return array{value: string, label: string, offset: string}
     */
    public static function option(string $identifier): array
    {
        return [
            'value' => $identifier,
            'label' => Str::of($identifier)->afterLast('/')->replace('_', ' ')->toString(),
            'offset' => self::gmtOffset(now($identifier)->utcOffset()),
        ];
    }

    /**
     * An offset in minutes as the pickers write it: "GMT", "GMT-3", "GMT+5:30".
     */
    private static function gmtOffset(int $minutes): string
    {
        if ($minutes === 0) {
            return 'GMT';
        }

        $sign = $minutes < 0 ? '-' : '+';
        $hours = intdiv(abs($minutes), 60);
        $rest = abs($minutes) % 60;

        if ($rest === 0) {
            return "GMT{$sign}{$hours}";
        }

        return sprintf('GMT%s%d:%02d', $sign, $hours, $rest);
    }
}
