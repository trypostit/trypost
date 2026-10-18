<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\InboxConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class InboxUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public string $workspaceId, public ?string $conversationId = null) {}

    public function broadcastAs(): string
    {
        return 'inbox.updated';
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("workspace.{$this->workspaceId}")];
    }

    /**
     * The conversation's kind and channel let the open inbox tell whether the
     * change belongs to the tab and channels on screen.
     *
     * @return array{conversation_id: string|null, kind: string|null, channel_id: string|null}
     */
    public function broadcastWith(): array
    {
        $conversation = filled($this->conversationId)
            ? InboxConversation::query()->whereKey($this->conversationId)->first(['id', 'kind', 'social_account_id'])
            : null;

        return [
            'conversation_id' => $this->conversationId,
            'kind' => $conversation?->kind?->value,
            'channel_id' => $conversation?->social_account_id,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
