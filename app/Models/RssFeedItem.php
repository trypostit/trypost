<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RssFeedItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RssFeedItem extends Model
{
    /** @use HasFactory<RssFeedItemFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'rss_feed_id',
        'guid_hash',
        'title',
        'url',
        'excerpt',
        'image_url',
        'image_checked_at',
        'author',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'image_checked_at' => 'datetime',
        ];
    }

    public static function hashGuid(string $value): string
    {
        return hash('sha256', $value);
    }

    public function feed(): BelongsTo
    {
        return $this->belongsTo(RssFeed::class, 'rss_feed_id');
    }

    public function image(): HasOne
    {
        return $this->hasOne(Media::class, 'rss_feed_item_id');
    }
}
