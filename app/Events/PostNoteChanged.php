<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

class PostNoteChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $postId,
        public string $workspaceId,
        public string $change,
    ) {}

    public function broadcastAs(): string
    {
        return 'post.note.changed';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("post.{$this->postId}"),
            new PrivateChannel("workspace.{$this->workspaceId}"),
        ];
    }

    /** @return array{post_id: string, change: string} */
    public function broadcastWith(): array
    {
        return ['post_id' => $this->postId, 'change' => $this->change];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
