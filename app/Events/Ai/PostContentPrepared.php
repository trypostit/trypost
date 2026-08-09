<?php

declare(strict_types=1);

namespace App\Events\Ai;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Phase A of the review flow: the AI has pre-generated the structured text of a
 * post draft and it's ready for the user to review/edit. Failure carries `error`.
 */
class PostContentPrepared implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userId,
        public string $draftId,
        public ?string $error = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'ai.content.prepared';
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}.ai-draft.{$this->draftId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'draft_id' => $this->draftId,
            'error' => $this->error,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
