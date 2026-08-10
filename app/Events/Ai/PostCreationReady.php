<?php

declare(strict_types=1);

namespace App\Events\Ai;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostCreationReady implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $userId,
        public string $creationId,
        public ?string $postId = null,
        public ?string $error = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'ai.creation.completed';
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}.ai-creation.{$this->creationId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'creation_id' => $this->creationId,
            'post_id' => $this->postId,
            'error' => $this->error,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
