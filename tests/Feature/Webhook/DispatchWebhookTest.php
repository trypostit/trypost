<?php

declare(strict_types=1);

use App\Actions\Post\UpdatePost;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\Webhook\EventType as WebhookEvent;
use App\Enums\Webhook\Status;
use App\Events\Webhook\LogUpdated;
use App\Jobs\DispatchWebhook;
use App\Mail\WebhookPausedMail;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
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

test('webhook service does not dispatch for paused webhooks', function () {
    Queue::fake();

    $this->webhook->update([
        'status' => Status::Paused,
        'events' => [WebhookEvent::PostPublished->value],
    ]);

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
    expect($log->id)->toBe($job->logId);
    expect(data_get($log->payload, 'id'))->toBe($log->id);
    expect($log->event_type)->toBe(WebhookEvent::PostPublished->value);
    expect($log->response_status)->toBe(200);
    expect($log->delivered_at)->not->toBeNull();
    expect($log->failed_at)->toBeNull();
    expect($log->attempts)->toBe(1);

    $this->webhook->refresh();

    expect($this->webhook->last_sent_at)->not->toBeNull();
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

    Http::assertSent(function ($request) use ($job) {
        $body = $request->data();

        return $body['id'] === $job->logId
            && $body['type'] === WebhookEvent::PostPublished->value
            && $body['data']['id'] === 'test-123'
            && isset($body['created_at']);
    });
});

test('dispatch webhook job sends the published post payload as data', function () {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $post = Post::factory()->published()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => '<p>Launch day. TryPost is live.</p>',
        'created_via' => CreatedVia::Web,
        'media' => [
            [
                'id' => 'm_01',
                'path' => 'medias/9f2c-hero.jpg',
                'url' => 'https://cdn.example.com/medias/9f2c-hero.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'hero.jpg',
                'source' => 'unsplash',
                'meta' => ['alt_text' => 'Product screenshot on a laptop'],
            ],
        ],
    ]);

    $label = WorkspaceLabel::factory()->recycle($this->workspace)->create([
        'name' => 'Launch',
        'color' => '#7C3AED',
    ]);
    $post->labels()->attach($label);

    $account = SocialAccount::factory()->linkedin()->recycle($this->workspace)->create([
        'display_name' => 'Paulo Castellano',
        'username' => 'paulocastellano',
        'avatar_url' => 'avatars/li.jpg',
    ]);
    PostPlatform::factory()->published()->recycle($post, $account)->create([
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'meta' => ['document_title' => 'TryPost launch deck'],
    ]);

    $payload = app(WebhookService::class)->postPayload($post->fresh());

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        $payload,
    );

    app()->call([$job, 'handle']);

    Http::assertSent(function ($request) use ($job, $payload) {
        $body = $request->data();

        return $body['id'] === $job->logId
            && $body['type'] === WebhookEvent::PostPublished->value
            && isset($body['created_at'])
            && $body['data'] === $payload
            && data_get($body, 'data.author.id') === $this->user->id
            && data_get($body, 'data.author.name') === $this->user->name
            && ! array_key_exists('email', data_get($body, 'data.author', []))
            && data_get($body, 'data.workspace.name') === $this->workspace->name
            && data_get($body, 'data.labels.0.name') === 'Launch'
            && data_get($body, 'data.media.0.type') === 'image'
            && data_get($body, 'data.platforms.0.platform') === Platform::LinkedIn->value
            && data_get($body, 'data.platforms.0.meta.document_title') === 'TryPost launch deck'
            && ! array_key_exists('access_token', data_get($body, 'data.platforms.0.social_account', []));
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

    $decoded = json_decode((string) $capturedBody, true);
    $expectedSignature = hash_hmac('sha256', $capturedBody, $this->webhook->signing_secret);

    expect($decoded['id'])->toBe($job->logId)
        ->and($capturedSignature)->toBe($expectedSignature);
});

test('unscheduling a post delivers the log id on the webhook envelope', function () {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $this->webhook->update([
        'events' => [WebhookEvent::PostUnscheduled->value],
    ]);

    $post = Post::factory()->scheduled()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Was scheduled',
    ]);

    UpdatePost::execute($this->workspace, $post, [
        'status' => PostStatus::Draft->value,
    ]);

    $log = WebhookLog::query()->where('webhook_id', $this->webhook->id)->first();

    expect($log)->not->toBeNull()
        ->and($log->event_type)->toBe(WebhookEvent::PostUnscheduled->value)
        ->and(data_get($log->payload, 'id'))->toBe($log->id);

    Http::assertSent(function ($request) use ($log, $post) {
        $body = $request->data();

        return $body['id'] === $log->id
            && $body['type'] === WebhookEvent::PostUnscheduled->value
            && data_get($body, 'data.id') === $post->id
            && data_get($body, 'data.status') === PostStatus::Draft->value;
    });
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

    $this->webhook->refresh();

    expect($this->webhook->last_sent_at)->toBeNull();
});

test('failed delivery does not overwrite last_sent_at', function () {
    $sentAt = now()->subHour()->startOfSecond();
    $this->webhook->update(['last_sent_at' => $sentAt]);

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
    }

    $this->webhook->refresh();

    expect($this->webhook->last_sent_at?->equalTo($sentAt))->toBeTrue();
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

    $this->webhook->refresh();

    expect($this->webhook->last_sent_at)->toBeNull();
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

    $this->webhook->refresh();

    expect($this->webhook->last_sent_at)->toBeNull();
});

test('dispatch webhook job skips delivery when the webhook is not enabled', function (Status $status) {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $this->webhook->update([
        'status' => $status,
        'consecutive_failures' => $status === Status::Paused ? 5 : 0,
        'paused_at' => $status === Status::Paused ? now() : null,
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    app()->call([$job, 'handle']);

    Http::assertNothingSent();
    expect(WebhookLog::query()->where('webhook_id', $this->webhook->id)->count())->toBe(0);

    $this->webhook->refresh();

    expect($this->webhook->last_sent_at)->toBeNull();
})->with([
    Status::Disabled,
    Status::Paused,
]);

test('dispatch webhook job delivers a forced replay when the webhook is not enabled', function () {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $this->webhook->update(['status' => Status::Disabled]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
        force: true,
    );

    app()->call([$job, 'handle']);

    Http::assertSentCount(1);
    expect(WebhookLog::query()->where('webhook_id', $this->webhook->id)->first()?->delivered_at)
        ->not->toBeNull();
});

test('successful delivery resets consecutive failures', function () {
    Http::fake([
        'example.com/webhook' => Http::response('OK', 200),
    ]);

    $this->webhook->update(['consecutive_failures' => 3]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    app()->call([$job, 'handle']);

    $this->webhook->refresh();

    expect($this->webhook->consecutive_failures)->toBe(0);
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

test('dispatch webhook job keeps the same log after serialize retry', function () {
    $sentIds = [];

    Http::fake(function ($request) use (&$sentIds) {
        $sentIds[] = $request->data()['id'] ?? null;

        if (count($sentIds) === 1) {
            return Http::response('Server Error', 500);
        }

        return Http::response('OK', 200);
    });

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException) {
    }

    expect(WebhookLog::query()->where('webhook_id', $this->webhook->id)->count())->toBe(1);

    $retried = unserialize(serialize($job));

    app()->call([$retried, 'handle']);

    expect($sentIds)->toHaveCount(2)
        ->and($sentIds[0])->toBe($job->logId)
        ->and($sentIds[1])->toBe($job->logId);
    expect(WebhookLog::query()->where('webhook_id', $this->webhook->id)->count())->toBe(1);
    expect(WebhookLog::query()->where('webhook_id', $this->webhook->id)->first()?->delivered_at)
        ->not->toBeNull();
});

test('dispatch webhook failed method does not increment when webhook is not enabled', function (Status $status) {
    $this->webhook->update([
        'status' => $status,
        'consecutive_failures' => $status === Status::Paused ? 5 : 0,
        'paused_at' => $status === Status::Paused ? now() : null,
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    $job->failed(new RuntimeException('Connection timeout'));

    $this->webhook->refresh();

    expect($this->webhook->consecutive_failures)->toBe($status === Status::Paused ? 5 : 0);
})->with([
    Status::Disabled,
    Status::Paused,
]);

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

test('dispatch webhook job broadcasts a slim log update', function (int $status) {
    Event::fake([LogUpdated::class]);

    Http::fake([
        'example.com/webhook' => Http::response($status === 200 ? 'OK' : 'Nope', $status),
    ]);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException) {
        // HTTP failures rethrow after the log is persisted.
    }

    Event::assertDispatched(LogUpdated::class, function (LogUpdated $event) use ($job): bool {
        $broadcast = $event->broadcastWith();

        return $event->log->id === $job->logId
            && array_keys($broadcast) === [
                'id',
                'event_type',
                'response_status',
                'delivered_at',
                'failed_at',
                'attempts',
                'created_at',
            ];
    });
})->with([
    'delivered' => 200,
    'rejected' => 500,
]);

test('dispatch webhook job broadcasts a slim log update when the endpoint is blocked', function () {
    Event::fake([LogUpdated::class]);

    $this->webhook->update(['endpoint' => 'http://127.0.0.1/webhook']);

    $job = new DispatchWebhook(
        $this->webhook,
        WebhookEvent::PostPublished->value,
        ['id' => 'test-123'],
    );

    try {
        app()->call([$job, 'handle']);
    } catch (RuntimeException) {
        // SSRF guard rethrows after the log is persisted.
    }

    Event::assertDispatched(LogUpdated::class, function (LogUpdated $event) use ($job): bool {
        return $event->log->id === $job->logId
            && ! array_key_exists('payload', $event->broadcastWith())
            && ! array_key_exists('response_body', $event->broadcastWith());
    });
});
