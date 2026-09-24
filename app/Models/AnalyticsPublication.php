<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
use Database\Factories\AnalyticsPublicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnalyticsPublication extends Model
{
    /** @use HasFactory<AnalyticsPublicationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'social_account_id',
        'social_account_key',
        'post_platform_id',
        'network',
        'platform_user_id',
        'platform',
        'provider_post_id',
        'provider_published_at',
        'origin',
        'content_type',
        'availability',
        'provider_content_type',
        'permalink',
        'excerpt',
        'preview_metadata',
        'account_display_name',
        'account_username',
        'account_avatar_url',
        'first_seen_at',
        'last_seen_at',
        'provider_synced_at',
        'provider_metadata',
    ];

    protected function casts(): array
    {
        return [
            'platform' => Platform::class,
            'provider_published_at' => 'immutable_datetime',
            'origin' => PublicationOrigin::class,
            'content_type' => PublicationContentType::class,
            'availability' => PublicationAvailability::class,
            'preview_metadata' => 'array',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'provider_synced_at' => 'immutable_datetime',
            'provider_metadata' => 'array',
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

    public function postPlatform(): BelongsTo
    {
        return $this->belongsTo(PostPlatform::class);
    }

    public function dailySnapshots(): HasMany
    {
        return $this->hasMany(AnalyticsPublicationDailySnapshot::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('availability', PublicationAvailability::Available);
    }
}
