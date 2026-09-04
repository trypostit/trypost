<?php

declare(strict_types=1);

namespace App\Events\Webhook;

use App\Models\WebhookLog;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Lightweight signal that a delivery log changed. Carries only list
 * metadata — the show page reloads `logs` over HTTP for payload and body.
 */
class LogUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 10];

    public function __construct(
        public WebhookLog $log,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("webhook.{$this->log->webhook_id}.logs"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'webhook.log.updated';
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    /**
     * @return array{
     *     id: string,
     *     event_type: string,
     *     response_status: int|null,
     *     delivered_at: string|null,
     *     failed_at: string|null,
     *     attempts: int,
     *     created_at: string
     * }
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->log->id,
            'event_type' => $this->log->event_type,
            'response_status' => $this->log->response_status,
            'delivered_at' => $this->log->delivered_at?->toIso8601String(),
            'failed_at' => $this->log->failed_at?->toIso8601String(),
            'attempts' => $this->log->attempts,
            'created_at' => $this->log->created_at->toIso8601String(),
        ];
    }
}
