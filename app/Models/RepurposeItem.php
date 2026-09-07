<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use Database\Factories\RepurposeItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RepurposeItem extends Model
{
    /** @use HasFactory<RepurposeItemFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'repurpose_id',
        'source_media_id',
        'source_permalink',
        'source_created_at',
        'status',
        'reason',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'status' => ItemStatus::class,
            'reason' => ItemReason::class,
            'source_created_at' => 'datetime',
        ];
    }

    public function repurpose(): BelongsTo
    {
        return $this->belongsTo(Repurpose::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
