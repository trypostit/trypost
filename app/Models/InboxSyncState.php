<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\SyncError;
use Database\Factories\InboxSyncStateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboxSyncState extends Model
{
    /** @use HasFactory<InboxSyncStateFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'social_account_id', 'kind', 'cursor', 'backfilled_at', 'last_synced_at', 'next_sync_at',
        'webhook_subscribed_at', 'last_webhook_at', 'last_error', 'error_category', 'consecutive_failures',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ConversationKind::class,
            'backfilled_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'next_sync_at' => 'datetime',
            'webhook_subscribed_at' => 'datetime',
            'last_webhook_at' => 'datetime',
            'error_category' => SyncError::class,
            'consecutive_failures' => 'integer',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * Makes every kind of the channel due again: after a (re)connect or a
     * scope change, a kind the dispatcher put off for missing access is
     * looked at on its next run.
     */
    public static function clearWaits(string $socialAccountId): void
    {
        self::query()->where('social_account_id', $socialAccountId)->update(['next_sync_at' => null]);
    }

    /**
     * Notes that a webhook delivered messages of these kinds for the channel:
     * while deliveries keep coming, polling only reconciles.
     *
     * @param  list<ConversationKind>  $kinds
     */
    public static function recordWebhook(SocialAccount $account, array $kinds): void
    {
        foreach (array_unique(array_map(fn (ConversationKind $kind): string => $kind->value, $kinds)) as $kind) {
            self::query()->updateOrCreate(['social_account_id' => $account->id, 'kind' => $kind], ['last_webhook_at' => now()]);
        }
    }

    public function receivesWebhooks(): bool
    {
        return filled($this->last_webhook_at)
            && $this->last_webhook_at->gte(now()->subHours((int) config('trypost.inbox.webhook_recent_hours')));
    }

    /**
     * What the inbox shows for this kind: a known cause first, then a sync
     * that failed several times in a row.
     */
    public function syncError(): ?SyncError
    {
        if (filled($this->error_category)) {
            return $this->error_category;
        }

        return $this->consecutive_failures >= SyncError::FAILING_AFTER ? SyncError::Failing : null;
    }

    /** @param Builder<self> $query */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where(fn (Builder $due) => $due->whereNull('next_sync_at')->orWhere('next_sync_at', '<=', now()));
    }
}
