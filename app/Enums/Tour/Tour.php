<?php

declare(strict_types=1);

namespace App\Enums\Tour;

/**
 * The in-app tours, named by their keys in `lang/<locale>/tours.php`
 * (mirrored by `TOURS` in `resources/js/lib/tours.ts`).
 */
enum Tour: string
{
    case PublishViews = 'publish.views';
}
