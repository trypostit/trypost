<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A queued media import finished, imported or failed. It names the import
 * only: the workspace channel reaches every member, so the composer that
 * started it reads the result from the import's status, which answers its
 * starter alone.
 */
class MediaImportSettled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $importId,
        public string $workspaceId,
    ) {}

    public function broadcastAs(): string
    {
        return 'media.import.settled';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->workspaceId}"),
        ];
    }

    /** @return array{import_id: string} */
    public function broadcastWith(): array
    {
        return ['import_id' => $this->importId];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
