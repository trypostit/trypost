<?php

declare(strict_types=1);

namespace App\Events;

use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * An Insights PDF is ready or failed, so the dialog that asked for it downloads
 * it or says so at once, instead of waiting for its next status check.
 */
class InsightsPdfExportFinished implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $exportId,
        public string $status,
    ) {}

    public function broadcastAs(): string
    {
        return 'insights-pdf.finished';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("insights-pdf.{$this->exportId}"),
        ];
    }

    /**
     * @return array{id: string, status: string, download_url: string|null}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->exportId,
            'status' => $this->status,
            'download_url' => $this->status === InsightsPdfExports::READY
                ? route('app.insights.pdf.download', $this->exportId)
                : null,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
