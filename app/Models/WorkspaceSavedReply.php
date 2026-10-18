<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\SavedReplyVersionObserver;
use Database\Factories\WorkspaceSavedReplyFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy(SavedReplyVersionObserver::class)]
class WorkspaceSavedReply extends Model
{
    /** @use HasFactory<WorkspaceSavedReplyFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const int NAME_MAX_LENGTH = 100;

    public const int CONTENT_MAX_LENGTH = 1000;

    protected $fillable = [
        'workspace_id',
        'name',
        'content',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
