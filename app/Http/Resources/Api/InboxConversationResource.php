<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Enums\Inbox\ConversationKind;
use App\Enums\Post\Origin;
use App\Enums\SocialAccount\Status;
use App\Support\Inbox\InboxMediaSlots;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;

class InboxConversationResource extends JsonResource
{
    /**
     * Whether the caller may send in this workspace, read once per request.
     */
    private function callerPublishesDirectly(Request $request): bool
    {
        $user = $request->user() ?? Auth::user();

        if (! $user) {
            return false;
        }

        $key = "inbox.publishes_directly.{$this->resource->workspace_id}";

        if (! $request->attributes->has($key)) {
            $workspace = $user->currentWorkspace?->id === $this->resource->workspace_id ? $user->currentWorkspace : $this->resource->workspace;
            $request->attributes->set($key, $workspace && $user->canPublishDirectlyIn($workspace));
        }

        return (bool) $request->attributes->get($key);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $account = $this->socialAccount;
        $post = $this->resource->relationLoaded('post') ? $this->post : null;
        $subject = $this->resource->subject();

        return [
            'id' => $this->id,
            'kind' => $this->kind?->value,
            'channel' => [
                'id' => $account?->id,
                'platform' => $account?->platform?->value,
                'username' => $account?->username,
                'display_name' => $account?->display_name,
                'avatar_url' => $account?->avatar_url,
            ],
            'subject' => [
                'text' => $subject['text'],
                'media' => InboxMediaSlots::itemUrls($subject['media']),
                'url' => $subject['url'],
                'published_at' => $subject['published_at']?->toIso8601String(),
            ],
            'post_id' => $this->post_id,
            'post' => $post ? [
                'id' => $post->id,
                'origin' => $post->origin?->value,
                'created_by' => $post->origin === Origin::TryPost ? $post->user?->name : null,
                'created_at' => $post->created_at?->toIso8601String(),
                'media' => collect($post->media ?? [])->map(fn (array $item): array => [
                    'id' => data_get($item, 'id'),
                    'url' => data_get($item, 'url'),
                    'type' => data_get($item, 'type'),
                    'mime_type' => data_get($item, 'mime_type'),
                    'meta' => ['cover_offset_ms' => data_get($item, 'meta.cover_offset_ms')],
                ])->values()->all(),
            ] : null,
            'participant' => [
                'name' => $this->participant_name,
                'username' => $this->participant_username,
                'avatar_url' => InboxMediaSlots::url($this->participant_avatar_path),
            ],
            'rating' => $this->rating,
            'open_count' => $this->open_count,
            'resolved' => $this->when($this->resource->isFlat(), fn (): ?bool => $this->resource->isResolved()),
            'replied_count' => $this->whenHas('replied_count'),
            'message_count' => $this->message_count,
            'inbound_count' => $this->inbound_count,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'last_inbound_at' => $this->last_inbound_at?->toIso8601String(),
            'reply_window_ends_at' => $this->resource->liveReplyWindowEndsAt()?->toIso8601String(),
            'can_send_message' => $this->resource->isFlat()
                && $this->resource->isReplyWindowOpen()
                && $account?->status === Status::Connected
                && $account->platform->inboxKindEnabled(ConversationKind::DirectMessage)
                && (bool) $account?->hasInboxAccessFor(ConversationKind::DirectMessage)
                && $this->callerPublishesDirectly($request),
            'last_message' => InboxMessageResource::make($this->whenLoaded('latestMessage')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'messages' => InboxMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
