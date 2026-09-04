<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Webhook\Status;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'endpoint',
        'events',
        'status',
        'signing_secret',
        'consecutive_failures',
        'paused_at',
        'last_sent_at',
    ];

    protected $hidden = [
        'signing_secret',
    ];

    protected $attributes = [
        'consecutive_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'status' => Status::class,
            'signing_secret' => 'encrypted',
            'paused_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    public static function generateSigningSecret(): string
    {
        return 'whsec_'.Str::random(32);
    }

    public function pause(): void
    {
        $this->update([
            'status' => Status::Paused,
            'paused_at' => now(),
        ]);
    }

    public function resetConsecutiveFailures(): void
    {
        if ($this->consecutive_failures > 0) {
            $this->update(['consecutive_failures' => 0]);
        }
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', Status::Enabled);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WebhookLog::class);
    }
}
