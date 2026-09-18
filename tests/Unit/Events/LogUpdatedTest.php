<?php

declare(strict_types=1);

use App\Events\Webhook\LogUpdated;
use App\Models\WebhookLog;
use Illuminate\Broadcasting\PrivateChannel;

$oversizedDelivery = [
    'payload' => [
        'id' => 'huge-post-payload',
        'type' => 'post.published',
        'data' => ['content' => str_repeat('x', 20_000)],
    ],
    'response_body' => str_repeat('y', 2_000),
];

test('event broadcasts on the webhook logs channel', function () {
    $log = WebhookLog::factory()->create();

    $channels = (new LogUpdated($log))->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe("private-webhook.{$log->webhook_id}.logs");
});

test('event broadcasts as a stable name on the broadcasts queue', function () {
    $log = WebhookLog::factory()->create();
    $event = new LogUpdated($log);

    expect($event->broadcastAs())->toBe('webhook.log.updated')
        ->and($event->broadcastQueue())->toBe('broadcasts');
});

test('event broadcasts log status without the delivery payload', function (WebhookLog $log) {
    $broadcast = (new LogUpdated($log))->broadcastWith();

    expect($broadcast)->toBe([
        'id' => $log->id,
        'event_type' => $log->event_type,
        'response_status' => $log->response_status,
        'delivered_at' => $log->delivered_at?->toIso8601String(),
        'failed_at' => $log->failed_at?->toIso8601String(),
        'attempts' => $log->attempts,
        'created_at' => $log->created_at->toIso8601String(),
    ])
        ->and($broadcast)->not->toHaveKeys(['payload', 'response_body', 'webhook_id'])
        ->and(strlen((string) json_encode($broadcast)))->toBeLessThan(10_000);
})->with([
    'delivered' => fn () => WebhookLog::factory()->create($oversizedDelivery),
    'failed' => fn () => WebhookLog::factory()->failed()->create($oversizedDelivery),
    'pending' => fn () => WebhookLog::factory()->pending()->create($oversizedDelivery),
]);
