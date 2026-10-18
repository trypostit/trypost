<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReviewReplyState;
use App\Enums\Post\Origin;
use Carbon\CarbonInterface;
use Database\Factories\InboxMessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;

class InboxMessage extends Model
{
    /** @use HasFactory<InboxMessageFactory> */
    use HasFactory, HasUuids;

    public const string REPLY_FAILED = 'reply_failed';

    public const string WINDOW_CLOSED = 'delivery_window_closed';

    public const string MESSAGES_UNAVAILABLE = 'delivery_messages_unavailable';

    /**
     * The conversations this message's tree left on its last upsert, so they
     * can be refreshed live. Never stored.
     *
     * @var list<string>
     */
    public array $leftConversationIds = [];

    protected $fillable = [
        'inbox_conversation_id', 'social_account_id',
        'platform_message_id', 'platform_message_hash', 'platform_parent_id', 'platform_parent_hash',
        'parent_id', 'root_id', 'direction', 'origin', 'sent_by_user_id', 'delivery_status', 'delivery_error',
        'author_platform_id', 'author_name', 'author_username', 'author_avatar_path',
        'body', 'media', 'meta', 'platform_url', 'platform_created_at', 'observed_at', 'review_reply_state',
        'answered_at', 'resolved_at', 'resolved_by_user_id', 'removed_at', 'hidden_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'origin' => Origin::Network->value,
    ];

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'origin' => Origin::class,
            'delivery_status' => DeliveryStatus::class,
            'review_reply_state' => ReviewReplyState::class,
            'media' => 'array',
            'meta' => 'array',
            'platform_created_at' => 'datetime',
            'observed_at' => 'datetime',
            'answered_at' => 'datetime',
            'resolved_at' => 'datetime',
            'removed_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(InboxConversation::class, 'inbox_conversation_id');
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function treeMessages(): HasMany
    {
        return $this->hasMany(self::class, 'root_id');
    }

    public function sentBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function isOpen(): bool
    {
        return $this->direction === MessageDirection::Inbound
            && blank($this->answered_at)
            && blank($this->resolved_at)
            && blank($this->removed_at);
    }

    /**
     * Messages of threads whose every message is older than the cutoff, so a
     * thread is pruned as a whole.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeInThreadsOlderThan(Builder $query, CarbonInterface $cutoff): Builder
    {
        return $query
            ->where('platform_created_at', '<', $cutoff)
            ->whereNotExists(fn (QueryBuilder $newer) => $newer->selectRaw('1')
                ->from('inbox_messages as newer')
                ->whereRaw('(newer.id = COALESCE(inbox_messages.root_id, inbox_messages.id) OR newer.root_id = COALESCE(inbox_messages.root_id, inbox_messages.id))')
                ->where(fn (QueryBuilder $kept) => $kept->where('newer.platform_created_at', '>=', $cutoff)->orWhereNull('newer.platform_created_at')));
    }

    /**
     * Messages the network still has: a removed one stays stored (for the
     * counts, a removal arriving before its message, a review reply sent
     * again) but is never listed.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNotRemoved(Builder $query): Builder
    {
        return $query->whereNull('removed_at');
    }

    /** @param Builder<self> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->where('direction', MessageDirection::Inbound->value)
            ->whereNull('answered_at')
            ->whereNull('resolved_at')
            ->whereNull('removed_at');
    }

    /**
     * One row of comment score totals: every message, the answered ones, and
     * for each day (UTC bounds of a local day) whether a message arrived that
     * day and whether one of those was answered the same day.
     *
     * @param  Builder<self>  $query
     * @param  list<array{0: CarbonInterface, 1: CarbonInterface}>  $days
     */
    public function scopeSelectScoreTotals(Builder $query, array $days): Builder
    {
        $query->selectRaw('COUNT(*) as comments_count, COUNT(answered_at) as answered_count');

        foreach (array_values($days) as $index => [$start, $end]) {
            $query->selectRaw(
                "MAX(CASE WHEN platform_created_at >= ? AND platform_created_at < ? THEN 1 ELSE 0 END) as day_{$index}_comments",
                [$start->utc(), $end->utc()],
            )->selectRaw(
                "MAX(CASE WHEN platform_created_at >= ? AND platform_created_at < ? AND answered_at >= ? AND answered_at < ? THEN 1 ELSE 0 END) as day_{$index}_replied",
                [$start->utc(), $end->utc(), $start->utc(), $end->utc()],
            );
        }

        return $query;
    }
}
