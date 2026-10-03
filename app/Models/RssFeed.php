<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RssFeed\Format;
use Database\Factories\RssFeedFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RssFeed extends Model
{
    /** @use HasFactory<RssFeedFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'rss_feed_collection_id',
        'url',
        'url_hash',
        'format',
        'title',
        'custom_title',
        'site_url',
        'icon_url',
        'last_fetched_at',
        'last_succeeded_at',
        'next_fetch_at',
        'refresh_requested_at',
        'consecutive_failures',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'format' => Format::class,
            'last_fetched_at' => 'datetime',
            'last_succeeded_at' => 'datetime',
            'next_fetch_at' => 'datetime',
            'refresh_requested_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    protected function displayTitle(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->custom_title ?? $this->title,
        );
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function collection(): BelongsTo
    {
        return $this->belongsTo(RssFeedCollection::class, 'rss_feed_collection_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RssFeedItem::class);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->whereNull('next_fetch_at')
            ->orWhere('next_fetch_at', '<=', now()));
    }
}
