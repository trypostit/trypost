<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'post_published',
        'post_failed',
        'account_disconnected',
        'mentioned_in_comment',
    ];

    protected function casts(): array
    {
        return [
            'post_published' => 'boolean',
            'post_failed' => 'boolean',
            'account_disconnected' => 'boolean',
            'mentioned_in_comment' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
