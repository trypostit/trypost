<?php

declare(strict_types=1);

namespace App\Enums\Changelog;

/**
 * The labels a "What's New" entry may carry, shown above its title. Like the
 * entry itself, the label is English only; each tag has a fixed color in
 * `ChangelogTagBadge.vue`.
 */
enum Tag: string
{
    case New = 'new';
    case Improved = 'improved';
    case Fixed = 'fixed';
    case Beta = 'beta';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Improved => 'Improved',
            self::Fixed => 'Fixed',
            self::Beta => 'Beta',
        };
    }
}
