<?php

declare(strict_types=1);

use App\Enums\Webhook\EventType as WebhookEvent;
use App\Enums\Webhook\Status;
use App\Events\Webhook\LogUpdated;
use App\Jobs\DispatchWebhook;
use App\Mail\WebhookPausedMail;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->webhook = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostPublished->value, WebhookEvent::PostFailed->value],
        'endpoint' => 'https://example.com/webhook',
    ]);
});

test('webhook service dispatches job for matching event', function () {
    Queue::fake();

    app(WebhookService::class)->dispatch(
        $this->workspace,
        WebhookEvent::PostPublished,
        ['id' => 'test-123'],
    );

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) {
        return $job->eventType === WebhookEvent::PostPublished->value
            && $job->webhook->id === $this->webhook->id;
    });
});

test('webhook service does not dispatch job for unsubscribed event', function () {
    Queue::fake();

    app(WebhookService::class)->dispatch(
        $this->workspace,
        WebhookEvent::PostCreated,
        ['id' => 'test-123'],
    );

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('webhook service does not dispatch for wildcard subscriptions', function () {
    Queue::fake();

    $this->webhook->update(['events' => ['*']]);

    app(WebhookService::class)->dispatch(
        $this->workspace,
        WebhookEvent::PostPublished,
        ['id' => 'test-123'],
    );

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('webhook service does not dispatch for disabled webhooks', function () {
    Queue::fake();

    $this->webhook->update(['status' => Status::Disabled]);

    app(WebhookService::class)->dispatch(
        $this->workspace,
        WebhookEvent::PostPublished,
        ['id' => 'test-123'],
    );

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('dispatch webhook job creates log and delivers successfully', function () {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    app()->call([$job, 'handle']);

    $log = WebhookLog::query()->where('webhook_id', $this->webhook->id)->first();

    expect($log)->not->toBeNull();
    expect($log->event_type)->toBe(WebhookEvent::PostPublished->value);
    expect($log->response_status)->toBe(200);
    expect($log->delivered_at)->not->toBeNull();
    expect($log->failed_at)->toBeNull();
    expect($log->attempts)->toBe(1);
});

test('dispatch webhook job sends correct signature header', function () {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    app()->call([$job, 'handle']);

    Http::assertSent(fn ($request) => $request->hasHeader('X-Webhook-Signature')
        && $request->hasHeader('Content-Type', 'application/json'));
});

test('dispatch webhook job sends correct payload structure', function () {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $body['type'] === WebhookEvent::PostPublished->value
            && $body['data']['id'] === 'test-123'
            && isset($body['created_at']);
    });
});

test('webhook signature can be verified with signing secret', function () {
    $capturedBody = null;
    $capturedSignature = null;

    Http::fake(function ($request) use (&$capturedBody, &$capturedSignature) {
        $capturedBody = $request->body();
        $capturedSignature = $request->header('X-Webhook-Signature')[0] ?? null;

        return Http::response('OK', 200);
    });

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    app()->call([$job, 'handle']);

    $expectedSignature = hash_hmac('sha256', $capturedBody, $this->webhook->signing_secret);

    expect($capturedSignature)->toBe($expectedSignature);
});

test('dispatch webhook job marks log as failed on http error', function () {
    Http::fake([
        'example.com/webhook' => Http::response('Server Error', 500),
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException) {
        // Expected
    }

    $log = WebhookLog::query()->where('webhook_id', $this->webhook->id)->first();

    expect($log->failed_at)->not->toBeNull();
    expect($log->delivered_at)->toBeNull();
    expect($log->response_status)->toBe(500);
    expect($log->response_body)->toBe('Server Error');
});

test('dispatch webhook job marks log as failed on connection error', function () {
    Http::fake([
        'example.com/webhook' => function () {
            throw new ConnectionException('Connection refused');
        },
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    try {
        app()->call([$job, 'handle']);
    } catch (Throwable) {
        // Expected
    }

    $log = WebhookLog::query()->where('webhook_id', $this->webhook->id)->first();

    expect($log->failed_at)->not->toBeNull();
});

test('dispatch webhook job rejects private endpoints', function () {
    $this->webhook->update(['endpoint' => 'http://127.0.0.1/webhook']);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException) {
        // Expected
    }

    $log = WebhookLog::query()->where('webhook_id', $this->webhook->id)->first();

    expect($log->failed_at)->not->toBeNull();
    expect($log->delivered_at)->toBeNull();
});

test('webhook service dispatches to multiple webhooks for same workspace', function () {
    Queue::fake();

    $webhook2 = Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostPublished->value],
        'endpoint' => 'https://other.com/webhook',
    ]);

    app(WebhookService::class)->dispatch(
        $this->workspace,
        WebhookEvent::PostPublished,
        ['id' => 'test-123'],
    );

    Queue::assertPushed(DispatchWebhook::class, 2);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) {
        return $job->webhook->id === $this->webhook->id;
    });

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($webhook2) {
        return $job->webhook->id === $webhook2->id;
    });
});

test('dispatch webhook job runs on the webhooks queue', function () {
    Queue::fake();

    DispatchWebhook::dispatch($this->webhook, WebhookEvent::PostPublished->value, ['id' => 'test-123']);

    Queue::assertPushedOn('webhooks', DispatchWebhook::class);
});

test('dispatch webhook job reuses existing log on retry', function () {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $log = WebhookLog::query()->create([
        'webhook_id' => $this->webhook->id,
        'event_type' => WebhookEvent::PostPublished->value,
        'payload' => ['type' => WebhookEvent::PostPublished->value, 'data' => ['id' => 'test-123']],
        'attempts' => 1,
        'failed_at' => now(),
        'response_status' => 500,
        'response_body' => 'Server Error',
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    $reflection = new ReflectionProperty($job, 'logId');
    $reflection->setValue($job, $log->id);

    app()->call([$job, 'handle']);

    $log->refresh();

    expect($log->delivered_at)->not->toBeNull();
    expect($log->failed_at)->toBeNull();
    expect($log->response_status)->toBe(200);
    expect(WebhookLog::query()->where('webhook_id', $this->webhook->id)->count())->toBe(1);
});

test('dispatch webhook failed method increments consecutive failures', function () {
    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    $job->failed(new RuntimeException('Connection timeout'));

    $this->webhook->refresh();

    expect($this->webhook->consecutive_failures)->toBe(1);
});

test('dispatch webhook failed method pauses webhook and sends email after 5 failures', function () {
    Mail::fake();

    $this->webhook->update(['consecutive_failures' => 4]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    $job->failed(new RuntimeException('Connection timeout'));

    $this->webhook->refresh();

    expect($this->webhook->consecutive_failures)->toBe(5);
    expect($this->webhook->status)->toBe(Status::Paused);
    expect($this->webhook->paused_at)->not->toBeNull();

    Mail::assertQueued(WebhookPausedMail::class, function (WebhookPausedMail $mail) {
        return $mail->hasTo($this->user->email);
    });
});

test('dispatch webhook job broadcasts log updates', function () {
    Event::fake([LogUpdated::class]);

    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    app()->call([$job, 'handle']);

    Event::assertDispatched(LogUpdated::class);
});
