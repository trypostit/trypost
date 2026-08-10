<?php

declare(strict_types=1);

namespace App\Events\Ai;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostMediaRegenerated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $media
     */
    public function __construct(
        public string $userId,
        public string $regenerationId,
        public string $postId,
        public ?array $media = null,
        public ?string $error = null,
    ) {}

    public function broadcastAs(): string
    {
        return 'ai.media.regenerated';
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}.ai-media.{$this->regenerationId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'regeneration_id' => $this->regenerationId,
            'post_id' => $this->postId,
            'media' => $this->media,
            'error' => $this->error,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
