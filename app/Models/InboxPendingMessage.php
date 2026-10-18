<?php

declare(strict_types=1);

namespace App\Models;

use App\Dto\Inbox\IncomingMessage;
use Database\Factories\InboxPendingMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A comment read before its post is in TryPost: it waits here, with what the
 * network sent, until ImportInboxPost imports the post and stores it.
 */
class InboxPendingMessage extends Model
{
    /** @use HasFactory<InboxPendingMessageFactory> */
    use HasFactory, HasUuids;

    /**
     * A comment whose post was not imported in this long is dropped: the
     * import gives up well before (ImportInboxPost::MAX_ATTEMPTS).
     */
    public const int KEEP_DAYS = 2;

    protected $fillable = [
        'social_account_id', 'platform_post_id', 'platform_post_hash', 'platform_message_hash', 'payload', 'observed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'observed_at' => 'datetime',
        ];
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function message(): IncomingMessage
    {
        return IncomingMessage::fromArray((array) $this->payload);
    }

    /** @param Builder<self> $query */
    public function scopeForPost(Builder $query, string $socialAccountId, string $platformPostId): Builder
    {
        return $query->where('social_account_id', $socialAccountId)->where('platform_post_hash', InboxConversation::hashOf($platformPostId));
    }
}
