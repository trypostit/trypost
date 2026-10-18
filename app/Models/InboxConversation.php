<?php

declare(strict_types=1);

namespace App\Models;

use App\Dto\MediaItem;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\InboxDrivers;
use App\Services\Social\ContentSanitizer;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Database\Factories\InboxConversationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InboxConversation extends Model
{
    /** @use HasFactory<InboxConversationFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id', 'social_account_id', 'analytics_publication_id', 'kind',
        'platform_conversation_id', 'platform_conversation_hash',
        'subject_text', 'subject_media', 'subject_url', 'subject_published_at', 'post_id',
        'participant_name', 'participant_username', 'participant_avatar_path', 'rating', 'meta',
        'open_count', 'message_count', 'inbound_count', 'last_message_at', 'last_inbound_at', 'notified_at', 'reply_window_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ConversationKind::class,
            'subject_media' => 'array',
            'meta' => 'array',
            'subject_published_at' => 'datetime',
            'last_message_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'notified_at' => 'datetime',
            'reply_window_ends_at' => 'datetime',
            'open_count' => 'integer',
            'message_count' => 'integer',
            'inbound_count' => 'integer',
            'rating' => 'integer',
        ];
    }

    public const string HAS_UNRESOLVED_INBOUND = 'has_unresolved_inbound';

    /**
     * The exists check `isResolved()` reads, for `withExists()` / `loadExists()`.
     *
     * @return array<string, Closure(Builder<InboxMessage>): void>
     */
    public static function unresolvedInboundCheck(): array
    {
        return [
            'messages as '.self::HAS_UNRESOLVED_INBOUND => fn (Builder $messages) => $messages
                ->where('direction', MessageDirection::Inbound->value)
                ->whereNull('resolved_at')
                ->whereNull('removed_at'),
        ];
    }

    /**
     * Every message the person wrote is resolved; null until the check was
     * loaded with the conversation.
     */
    public function isResolved(): ?bool
    {
        if (! array_key_exists(self::HAS_UNRESOLVED_INBOUND, $this->getAttributes())) {
            return null;
        }

        return ! $this->getAttribute(self::HAS_UNRESOLVED_INBOUND) && $this->inbound_count > 0;
    }

    public static function hashOf(string $platformId): string
    {
        return hash('sha256', $platformId);
    }

    /**
     * Whether the channel keeps this conversation with what it is about: a
     * comment conversation under its post, any other with its subject read.
     */
    public static function knows(SocialAccount $account, ConversationKind $kind, string $platformId): bool
    {
        return self::query()
            ->where('social_account_id', $account->id)
            ->where('kind', $kind)
            ->where('platform_conversation_hash', self::hashOf($platformId))
            ->whereNotNull($kind === ConversationKind::Comment ? 'post_id' : 'subject_url')
            ->exists();
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function analyticsPublication(): BelongsTo
    {
        return $this->belongsTo(AnalyticsPublication::class);
    }

    /**
     * The post of the channel this conversation's subject is, published
     * through TryPost or imported from the network.
     *
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * What the conversation is about. A comment conversation reads its post
     * (eager load `post` with content, media, platform_url and published_at);
     * a mention keeps the third-party post it was read with.
     *
     * @return array{text: ?string, media: list<array<string, mixed>>, url: ?string, published_at: ?CarbonInterface}
     */
    public function subject(): array
    {
        $post = $this->kind === ConversationKind::Comment && filled($this->post_id)
            ? ($this->relationLoaded('post') ? $this->post : $this->post()->first())
            : null;

        if ($post) {
            return [
                'text' => app(ContentSanitizer::class)->plainText((string) $post->content),
                'media' => $post->media_items
                    ->filter(fn (MediaItem $item): bool => $item->isImage())
                    ->map(fn (MediaItem $item): array => ['type' => MediaType::Image->value, 'path' => $item->path])
                    ->values()
                    ->all(),
                'url' => $post->platform_url,
                'published_at' => $post->published_at,
            ];
        }

        return [
            'text' => $this->subject_text,
            'media' => array_values($this->subject_media ?? []),
            'url' => $this->subject_url,
            'published_at' => $this->subject_published_at,
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(InboxMessage::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(InboxMessage::class)->orderByDesc('platform_created_at')->orderByDesc('id')->limit(1);
    }

    /**
     * The direct message driver of this conversation's network, if any.
     */
    public function directMessageDriver(): ?DirectMessageDriver
    {
        if (! $this->isFlat()) {
            return null;
        }

        $platform = $this->loadMissing('socialAccount')->socialAccount?->platform;
        $driver = $platform ? app(InboxDrivers::class)->for($platform) : null;

        return $driver instanceof DirectMessageDriver ? $driver : null;
    }

    /**
     * Until when the network accepts an answer, read now from the person's
     * last message, never from the stored column, which only caches it.
     */
    public function liveReplyWindowEndsAt(): ?CarbonImmutable
    {
        return $this->directMessageDriver()?->replyWindowEndsAt($this->last_inbound_at?->toImmutable());
    }

    /**
     * A network with a window accepts an answer only inside it; a
     * conversation the person never wrote in has none.
     */
    public function isReplyWindowOpen(): bool
    {
        $driver = $this->directMessageDriver();

        if (! $driver) {
            return false;
        }

        return ! $driver->hasReplyWindow() || (bool) $this->liveReplyWindowEndsAt()?->isFuture();
    }

    /**
     * A direct message conversation: its messages have no parent and no root,
     * and an answer covers every earlier message of the conversation.
     */
    public function isFlat(): bool
    {
        return $this->kind === ConversationKind::DirectMessage;
    }

    /**
     * Conversations of every tab, each under its own tab's rule.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleIn(Builder $query, Workspace $workspace): Builder
    {
        return $query->where(fn (Builder $tabs) => collect(InboxTab::cases())->each(
            fn (InboxTab $tab) => $tabs->orWhere(fn (Builder $visible) => $visible->visibleInTab($workspace, $tab)),
        ));
    }

    public function isVisible(): bool
    {
        return (bool) $this->socialAccount?->platform->inboxKindEnabled($this->kind);
    }

    /**
     * Conversations of one inbox tab; mentions and direct messages also need
     * their network's mentions or messages turned on.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleInTab(Builder $query, Workspace $workspace, InboxTab $tab): Builder
    {
        $platforms = array_values(array_filter(Platform::inboxValues(), fn (string $value): bool => $tab->enabledOn(Platform::from($value))));

        return $query
            ->where('workspace_id', $workspace->id)
            ->whereIn('kind', $tab->kindValues())
            ->whereHas('socialAccount', fn (Builder $accounts) => $accounts->whereIn('platform', $platforms));
    }
}
