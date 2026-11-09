<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use IntlTimeZone;

class Timezone
{
    public const DEFAULT = 'UTC';

    public static function normalize(?string $value): string
    {
        if (! is_string($value)) {
            return self::DEFAULT;
        }

        $canonical = once(fn (): array => array_flip(DateTimeZone::listIdentifiers()));

        if (isset($canonical[$value])) {
            return $value;
        }

        $mapped = IntlTimeZone::getIanaID($value);

        return is_string($mapped) && isset($canonical[$mapped])
            ? $mapped
            : self::DEFAULT;
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
