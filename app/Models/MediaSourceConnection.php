<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Media\Source;
use Database\Factories\MediaSourceConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person's remembered sign-in to a media source (Canva). One per user and
 * source; the tokens are encrypted at rest and never serialized.
 */
class MediaSourceConnection extends Model
{
    /** @use HasFactory<MediaSourceConnectionFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'source',
        'external_user_id',
        'external_team_id',
        'access_token',
        'refresh_token',
        'expires_at',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    protected function casts(): array
    {
        return [
            'source' => Source::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
