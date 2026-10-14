<?php

declare(strict_types=1);

namespace App\Enums\Changelog;

/**
 * The labels a "What's New" entry may carry, shown above its title. Each one
 * has a fixed color in `ChangelogTagBadge.vue` and a label in
 * `lang/<locale>/changelog.php` (Beta reuses `common.beta`).
 */
enum Tag: string
{
    case New = 'new';
    case Improved = 'improved';
    case Fixed = 'fixed';
    case Beta = 'beta';
}
