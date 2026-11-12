<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A note of the post was created, updated (its text or its reactions) or
 * deleted. It names the note, so an open thread updates that note alone.
 */
class PostNoteChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $postId,
        public string $workspaceId,
        public string $change,
        public ?string $noteId = null,
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

    /** @return array{post_id: string, note_id: string|null, change: string} */
    public function broadcastWith(): array
    {
        return ['post_id' => $this->postId, 'note_id' => $this->noteId, 'change' => $this->change];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
