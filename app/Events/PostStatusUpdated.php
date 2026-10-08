<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Post;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostStatusUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Post $post) {}

    public function broadcastAs(): string
    {
        return 'post.platform.status.updated';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->post->workspace_id}"),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'post_id' => $this->post->id,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
