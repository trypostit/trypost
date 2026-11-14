<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;
use Carbon\CarbonTimeZone;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use IntlTimeZone;

class Timezone
{
    public const DEFAULT = 'UTC';

    public static function normalize(?string $value): string
    {
        return self::known($value) ?? self::DEFAULT;
    }

    /**
     * The canonical identifier of a zone PHP knows (its IANA alias resolved),
     * or null for a blank or unknown one.
     */
    public static function known(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $canonical = once(fn (): Collection => collect(DateTimeZone::listIdentifiers())->flip());

        return collect([$value, IntlTimeZone::getIanaID($value)])
            ->first(fn (mixed $zone): bool => is_string($zone) && $canonical->has($zone));
    }

    /**
     * A stored zone made safe to compute with: its canonical identifier, or the
     * zone itself when PHP knows it without one (Etc/GMT+10), so a zone PHP
     * understands never shifts to UTC; only one it does not know is replaced.
     */
    public static function usable(string $value): string
    {
        $canonical = self::known($value);

        if (filled($canonical)) {
            return $canonical;
        }

        $understood = once(fn (): Collection => collect(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC))->flip());

        if ($understood->has($value)) {
            return $value;
        }

        return self::DEFAULT;
    }

    /**
     * @return list<array{value: string, label: string, offset: string}>
     */
    public static function options(): array
    {
        $now = now();

        return collect(DateTimeZone::listIdentifiers())
            ->map(fn (string $identifier): array => self::option($identifier, $now))
            ->all();
    }

    /**
     * A canonical time zone as the pickers show it: its city ("Sao Paulo") and
     * its offset at that instant ("GMT-3").
     *
     * @return array{value: string, label: string, offset: string}
     */
    public static function option(string $identifier, ?CarbonInterface $at = null): array
    {
        return [
            'value' => $identifier,
            'label' => Str::of($identifier)->afterLast('/')->replace('_', ' ')->toString(),
            'offset' => self::gmtOffset(intdiv(CarbonTimeZone::create($identifier)->getOffset($at ?? now()), 60)),
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
