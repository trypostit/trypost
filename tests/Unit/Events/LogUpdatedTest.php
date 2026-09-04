<?php

declare(strict_types=1);

use App\Events\Webhook\LogUpdated;
use App\Models\WebhookLog;
use Illuminate\Broadcasting\PrivateChannel;

test('event broadcasts on the webhook logs channel', function () {
    $log = WebhookLog::factory()->create();

    $channels = (new LogUpdated($log))->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe("private-webhook.{$log->webhook_id}.logs");
});

test('event broadcasts as a stable name', function () {
    $log = WebhookLog::factory()->create();

    expect((new LogUpdated($log))->broadcastAs())->toBe('webhook.log.updated');
});

test('event broadcasts log status without the delivery payload', function () {
    $log = WebhookLog::factory()->create([
        'payload' => [
            'id' => 'huge-post-payload',
            'type' => 'post.published',
            'data' => ['content' => str_repeat('x', 20_000)],
        ],
        'response_body' => str_repeat('y', 2_000),
    ]);

    expect((new LogUpdated($log))->broadcastWith())->toBe([
        'id' => $log->id,
        'event_type' => $log->event_type,
        'response_status' => $log->response_status,
        'delivered_at' => $log->delivered_at?->toIso8601String(),
        'failed_at' => $log->failed_at?->toIso8601String(),
        'attempts' => $log->attempts,
        'created_at' => $log->created_at->toIso8601String(),
    ]);
});
