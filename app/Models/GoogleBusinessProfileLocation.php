<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GoogleBusinessProfileLocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleBusinessProfileLocation extends Model
{
    /** @use HasFactory<GoogleBusinessProfileLocationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'social_account_id',
        'google_account_name',
        'google_location_name',
        'title',
        'store_code',
        'timezone',
        'maps_uri',
        'website_uri',
        'phone_number',
        'storefront_address',
        'metadata',
        'is_selected',
        'is_verified',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'storefront_address' => 'array',
            'metadata' => 'array',
            'is_selected' => 'boolean',
            'is_verified' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function postPlatforms(): HasMany
    {
        return $this->hasMany(PostPlatform::class);
    }
}
