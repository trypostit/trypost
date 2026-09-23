<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use Database\Factories\AnalyticsSyncStateFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsSyncState extends Model
{
    /** @use HasFactory<AnalyticsSyncStateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'social_account_id', 'collector', 'status', 'checkpoint', 'target_since',
        'oldest_reached_at', 'high_watermark_at', 'last_success_at',
        'last_error_category',
    ];

    protected function casts(): array
    {
        return [
            'collector' => SyncCollector::class,
            'status' => SyncStatus::class,
            'checkpoint' => 'array',
            'target_since' => 'immutable_datetime',
            'oldest_reached_at' => 'immutable_datetime',
            'high_watermark_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
