<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Collection;
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
        $now = new DateTimeImmutable('now');

        return array_map(function (string $identifier) use ($now): array {
            $seconds = (new DateTimeZone($identifier))->getOffset($now);
            $sign = $seconds < 0 ? '-' : '+';
            $hours = intdiv(abs($seconds), 3600);
            $minutes = intdiv(abs($seconds) % 3600, 60);
            $offset = $seconds === 0
                ? 'GMT'
                : ($minutes === 0 ? "GMT{$sign}{$hours}" : sprintf('GMT%s%d:%02d', $sign, $hours, $minutes));
            $segments = explode('/', $identifier);

            return [
                'value' => $identifier,
                'label' => str_replace('_', ' ', (string) end($segments)),
                'offset' => $offset,
            ];
        }, DateTimeZone::listIdentifiers());
    }
}
