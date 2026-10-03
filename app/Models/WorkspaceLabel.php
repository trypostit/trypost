<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkspaceLabelFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkspaceLabel extends Model
{
    /** @use HasFactory<WorkspaceLabelFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'workspace_id',
        'name',
        'color',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    public function ideas(): BelongsToMany
    {
        return $this->belongsToMany(Idea::class);
    }
}
