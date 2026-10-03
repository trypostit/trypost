<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\ObservationProvenance;
use App\Enums\SocialAccount\Platform;
use Database\Factories\AnalyticsAccountDailySnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsAccountDailySnapshot extends Model
{
    /** @use HasFactory<AnalyticsAccountDailySnapshotFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'social_account_id',
        'social_account_key',
        'network',
        'platform_user_id',
        'platform',
        'account_display_name',
        'account_username',
        'account_avatar_url',
        'date',
        'followers_count',
        'metrics',
        'provenance',
        'precision',
        'provider_observed_at',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'date' => 'immutable_date',
            'followers_count' => 'integer',
            'metrics' => 'array',
            'provenance' => ObservationProvenance::class,
            'precision' => MetricPrecision::class,
            'provider_observed_at' => 'immutable_datetime',
            'collected_at' => 'immutable_datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
