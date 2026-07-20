<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ContentRatingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ContentRating extends Model
{
    /** @use HasFactory<ContentRatingFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'rateable_type',
        'rateable_id',
        'rating',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function rateable(): MorphTo
    {
        return $this->morphTo();
    }
}
