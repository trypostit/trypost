<?php

declare(strict_types=1);

use App\Models\Webhook;
use App\Models\WebhookLog;

test('deletes webhook logs older than 7 days', function () {
    $webhook = Webhook::factory()->create();

    $old = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
        'created_at' => now()->subDays(8),
    ]);

    $recent = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
        'created_at' => now()->subDays(3),
    ]);

    $this->artisan('app:prune-webhook-logs')
        ->assertSuccessful();

    expect(WebhookLog::find($old->id))->toBeNull();
    expect(WebhookLog::find($recent->id))->not->toBeNull();
});

test('keeps logs exactly 7 days old', function () {
    $webhook = Webhook::factory()->create();

    $borderline = WebhookLog::factory()->create([
        'webhook_id' => $webhook->id,
        'created_at' => now()->subDays(7),
    ]);

    $this->artisan('app:prune-webhook-logs')
        ->assertSuccessful();

    expect(WebhookLog::find($borderline->id))->not->toBeNull();
});

test('handles empty table gracefully', function () {
    $this->artisan('app:prune-webhook-logs')
        ->assertSuccessful();

    expect(WebhookLog::count())->toBe(0);
});
