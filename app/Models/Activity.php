<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * An activity log row (spatie/laravel-activitylog) with a UUID key and the
 * workspace it happened in, null for backoffice entries.
 *
 * @property string $id
 * @property string|null $workspace_id
 */
class Activity extends SpatieActivity
{
    use HasUuids;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
