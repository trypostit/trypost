<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Analytics\ExposureKind;
use Database\Factories\AnalyticsPublicationDailySnapshotFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsPublicationDailySnapshot extends Model
{
    /** @use HasFactory<AnalyticsPublicationDailySnapshotFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'publication_id',
        'date',
        'collected_at',
        'provider_observed_at',
        'metrics',
        'reactions_count',
        'comments_count',
        'shares_count',
        'saves_count',
        'views_count',
        'impressions_count',
        'reach_count',
        'engagement_count',
        'exposure_count',
        'exposure_kind',
        'watch_time_milliseconds',
        'average_watch_time_milliseconds',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'collected_at' => 'immutable_datetime',
            'provider_observed_at' => 'immutable_datetime',
            'metrics' => 'array',
            'reactions_count' => 'integer',
            'comments_count' => 'integer',
            'shares_count' => 'integer',
            'saves_count' => 'integer',
            'views_count' => 'integer',
            'impressions_count' => 'integer',
            'reach_count' => 'integer',
            'engagement_count' => 'integer',
            'exposure_count' => 'integer',
            'exposure_kind' => ExposureKind::class,
            'watch_time_milliseconds' => 'integer',
            'average_watch_time_milliseconds' => 'integer',
        ];
    }

    public function publication(): BelongsTo
    {
        return $this->belongsTo(AnalyticsPublication::class, 'publication_id');
    }
}
