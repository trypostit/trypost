<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TelegramChannelConnected implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $workspaceId, public string $nonce, public string $accountId, public bool $created) {}

    public function broadcastAs(): string
    {
        return 'telegram.channel.connected';
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->workspaceId}"),
        ];
    }

    /**
     * @return array<string, string|bool>
     */
    public function broadcastWith(): array
    {
        return [
            'nonce' => $this->nonce,
            'account_id' => $this->accountId,
            'created' => $this->created,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
