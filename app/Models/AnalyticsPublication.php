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
        'post_id',
        'post_dismissed_at',
        'network',
        'platform_user_id',
        'platform',
        'remote_id',
        'provider_published_at',
        'origin',
        'content_type',
        'availability',
        'metric_failures',
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
            'post_dismissed_at' => 'immutable_datetime',
            'provider_published_at' => 'immutable_datetime',
            'origin' => PublicationOrigin::class,
            'content_type' => PublicationContentType::class,
            'availability' => PublicationAvailability::class,
            'metric_failures' => 'integer',
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

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function dailySnapshots(): HasMany
    {
        return $this->hasMany(AnalyticsPublicationDailySnapshot::class, 'publication_id');
    }

    /**
     * The `provider_metadata` keys under which Facebook discovery reports the
     * video or photo a Page post carries, which TryPost may have saved as the
     * post's id.
     *
     * @var list<string>
     */
    public const array FACEBOOK_MEDIA_KEYS = ['video_id', 'photo_id'];

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('availability', PublicationAvailability::Available);
    }

    /**
     * Available, linked to no post and not dismissed: what the import may link
     * or turn into a post.
     */
    public function scopeLinkable(Builder $query): Builder
    {
        return $query->available()->whereNull('post_id')->whereNull('post_dismissed_at');
    }

    /**
     * Carrying one of `$mediaIds` as its Facebook video or photo; an empty list
     * matches nothing.
     *
     * @param  list<string>  $mediaIds
     */
    public function scopeCarryingFacebookMedia(Builder $query, array $mediaIds): Builder
    {
        return $query->where(fn (Builder $carrying): Builder => collect(self::FACEBOOK_MEDIA_KEYS)
            ->reduce(fn (Builder $carry, string $key): Builder => $carry->orWhereIn("provider_metadata->{$key}", $mediaIds), $carrying));
    }

    /**
     * Carrying a Facebook video or photo, whichever.
     */
    public function scopeCarryingAnyFacebookMedia(Builder $query): Builder
    {
        return $query->where(fn (Builder $carrying): Builder => collect(self::FACEBOOK_MEDIA_KEYS)
            ->reduce(fn (Builder $carry, string $key): Builder => $carry->orWhereNotNull("provider_metadata->{$key}"), $carrying));
    }

    public function isLinkable(): bool
    {
        return $this->availability === PublicationAvailability::Available
            && blank($this->post_id)
            && blank($this->post_dismissed_at);
    }
}
