<?php

declare(strict_types=1);

namespace App\Support\Admin;

use Illuminate\Support\Str;

/**
 * A stored key as people read it, as the backoffice's `humanize()` writes it
 * on the frontend (`resources/js/lib/adminFormat.ts`): `at_risk` becomes
 * "At risk".
 */
final class KeyLabel
{
    public static function of(string $key): string
    {
        return Str::ucfirst(Str::replace('_', ' ', $key));
    }
}
