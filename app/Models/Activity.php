<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * An activity log row (spatie/laravel-activitylog) with a UUID key.
 *
 * @property string $id
 */
class Activity extends SpatieActivity
{
    use HasUuids;
}
