<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use Database\Factories\RepurposeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Repurpose extends Model
{
    /** @use HasFactory<RepurposeFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'source_social_account_id',
        'source_format',
        'publish_mode',
        'destinations',
        'status',
        'activated_at',
        'last_polled_at',
        'next_poll_at',
        'last_error',
    ];

    protected $attributes = [
        'destinations' => '[]',
    ];

    protected function casts(): array
    {
        return [
            'destinations' => 'array',
            'source_format' => SourceFormat::class,
            'publish_mode' => PublishMode::class,
            'status' => Status::class,
            'activated_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'next_poll_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'source_social_account_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RepurposeItem::class);
    }
}
