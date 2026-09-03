<?php

declare(strict_types=1);

use App\Enums\Webhook\EventType as WebhookEvent;
use App\Jobs\DispatchWebhook;
use App\Models\Post;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Services\WebhookService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->service = app(WebhookService::class);
});

test('ping sends POST request to endpoint', function () {
    Http::fake([
        'https://example.com/hook' => Http::response([], 200),
    ]);

    $this->service->ping('https://example.com/hook');

    Http::assertSent(fn ($request) => $request->url() === 'https://example.com/hook'
        && $request->method() === 'POST'
        && $request['type'] === 'webhook.test'
    );
});

test('ping throws when endpoint is unreachable', function () {
    Http::fake([
        'https://example.com/unreachable' => Http::throw(fn () => throw new ConnectionException('Connection refused')),
    ]);

    $this->service->ping('https://example.com/unreachable');
})->throws(RuntimeException::class, 'The endpoint is not reachable.');

test('ping throws when endpoint returns non-200', function () {
    Http::fake([
        'https://example.com/hook' => Http::response([], 500),
    ]);

    $this->service->ping('https://example.com/hook');
})->throws(RuntimeException::class, 'The endpoint returned HTTP 500.');

test('ping rejects private network endpoints', function () {
    $this->service->ping('http://127.0.0.1/hook');
})->throws(RuntimeException::class, 'This endpoint is not allowed.');

test('dispatch dispatches DispatchWebhook for matching webhooks', function () {
    Queue::fake();

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostPublished->value, WebhookEvent::PostFailed->value],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostPublished, ['foo' => 'bar']);

    Queue::assertPushed(DispatchWebhook::class);
});

test('dispatch does not dispatch for webhooks without matching events', function () {
    Queue::fake();

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostFailed->value],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostPublished, ['foo' => 'bar']);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('dispatch does not dispatch for disabled webhooks', function () {
    Queue::fake();

    Webhook::factory()->disabled()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [WebhookEvent::PostPublished->value],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostPublished, ['foo' => 'bar']);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('dispatch does not match wildcard events', function () {
    Queue::fake();

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => ['*'],
    ]);

    $this->service->dispatch($this->workspace, WebhookEvent::PostPublished, ['foo' => 'bar']);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('postPayload includes the post lifecycle fields', function () {
    $post = Post::factory()->published()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello world',
    ]);

    $payload = $this->service->postPayload($post);

    expect($payload)->toEqual([
        'id' => $post->id,
        'workspace_id' => $post->workspace_id,
        'status' => 'published',
        'content' => 'Hello world',
        'scheduled_at' => null,
        'published_at' => $post->published_at?->toIso8601String(),
    ]);
});
