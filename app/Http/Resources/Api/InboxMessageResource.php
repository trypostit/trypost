<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReviewRejectionReason;
use App\Enums\Inbox\ReviewReplyState;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Support\Inbox\InboxMediaSlots;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InboxMessageResource extends JsonResource
{
    /**
     * The message's channel, whose picture every message it wrote shows; read
     * once per channel and request when the relation is not loaded.
     */
    private function channel(Request $request): ?SocialAccount
    {
        if ($this->resource->relationLoaded('socialAccount')) {
            return $this->socialAccount;
        }

        $key = "inbox.channel.{$this->social_account_id}";

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, SocialAccount::query()->find($this->social_account_id));
        }

        return $request->attributes->get($key);
    }

    /**
     * The message's conversation, read once per conversation and request when
     * the relation is not loaded.
     */
    private function conversationOf(Request $request): ?InboxConversation
    {
        if ($this->resource->relationLoaded('conversation')) {
            return $this->conversation;
        }

        $key = "inbox.conversation.{$this->inbox_conversation_id}";

        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, InboxConversation::query()->find($this->inbox_conversation_id));
        }

        return $request->attributes->get($key);
    }

    /**
     * The channel's picture for what it wrote, the participant's for every
     * message of a direct conversation, and the author's own elsewhere.
     */
    private function avatarUrl(Request $request): ?string
    {
        if ($this->direction === MessageDirection::Outbound) {
            return $this->channel($request)?->avatar_url;
        }

        $conversation = $this->conversationOf($request);

        if ($conversation?->kind === ConversationKind::DirectMessage) {
            return InboxMediaSlots::url($conversation->participant_avatar_path);
        }

        return InboxMediaSlots::url($this->author_avatar_path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sentBy = $this->relationLoaded('sentBy') ? $this->sentBy : null;

        return [
            'id' => $this->id,
            'conversation_id' => $this->inbox_conversation_id,
            'parent_id' => $this->parent_id,
            'root_id' => $this->root_id,
            'direction' => $this->direction?->value,
            'origin' => $this->origin?->value,
            'author' => [
                'name' => $this->author_name,
                'username' => $this->author_username,
                'avatar_url' => $this->avatarUrl($request),
            ],
            'body' => $this->body,
            'media' => InboxMediaSlots::itemUrls($this->media),
            'content' => InboxMediaSlots::contentUrls(data_get($this->meta, 'content')),
            'media_pending' => InboxMediaSlots::hasPendingContent($this->resource),
            'reactions' => collect((array) data_get($this->meta, 'reactions', []))->pluck('emoji')->values()->all(),
            'platform_url' => $this->platform_url,
            'platform_created_at' => $this->platform_created_at?->toIso8601String(),
            'delivery_status' => $this->delivery_status?->value,
            'delivery_error' => filled($this->delivery_error)
                ? __("inbox.errors.{$this->delivery_error}", ['network' => $this->resource->loadMissing('socialAccount')->socialAccount?->platform->label()])
                : null,
            'review_reply_state' => $this->review_reply_state?->value,
            'policy_violation' => data_get($this->meta, 'policy_violation'),
            'rejection_reason' => $this->review_reply_state === ReviewReplyState::Rejected
                ? ReviewRejectionReason::fromGoogle(data_get($this->meta, 'policy_violation'))->message()
                : null,
            'sent_by' => $sentBy ? ['id' => $sentBy->id, 'name' => $sentBy->name] : null,
            'answered_at' => $this->answered_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'removed_at' => $this->removed_at?->toIso8601String(),
            'hidden_at' => $this->hidden_at?->toIso8601String(),
            'is_open' => $this->isOpen(),
            'is_subject' => $this->when(
                $this->relationLoaded('conversation') && filled($this->conversation),
                fn (): bool => $this->platform_message_hash === $this->conversation->platform_conversation_hash,
            ),
            'replies' => self::collection($this->whenLoaded('replies')),
            'tree' => self::collection($this->whenLoaded('treeMessages')),
            'conversation' => InboxConversationResource::make($this->whenLoaded('conversation')),
        ];
    }
}
