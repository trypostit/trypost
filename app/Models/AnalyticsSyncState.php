<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use Database\Factories\AnalyticsSyncStateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsSyncState extends Model
{
    /** @use HasFactory<AnalyticsSyncStateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'social_account_id',
        'workspace_id',
        'network',
        'platform_user_id',
        'collector',
        'status',
        'checkpoint',
        'target_since',
        'oldest_reached_at',
        'high_watermark_at',
        'last_success_at',
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

    public function scopeForCollector(Builder $query, SyncCollector $collector): Builder
    {
        return $query->where('collector', $collector);
    }

    public function scopeForIdentity(Builder $query, SocialAccount $account): Builder
    {
        return $query->where(self::identityFor($account));
    }

    /** @return array{workspace_id: string, network: string, platform_user_id: string} */
    public static function identityFor(SocialAccount $account): array
    {
        return [
            'workspace_id' => $account->workspace_id,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
        ];
    }

    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SyncStatus::Complete,
            SyncStatus::Partial,
            SyncStatus::ProviderLimited,
            SyncStatus::Failed,
        ]);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [
            SyncStatus::Complete,
            SyncStatus::Partial,
            SyncStatus::ProviderLimited,
            SyncStatus::Failed,
        ], true);
    }
}
