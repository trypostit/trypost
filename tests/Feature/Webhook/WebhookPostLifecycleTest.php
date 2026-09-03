<?php

declare(strict_types=1);

use App\Actions\Post\CreatePost;
use App\Actions\Post\DeletePost;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\Webhook\EventType;
use App\Events\PostCreated;
use App\Events\PostDeleted;
use App\Events\PostStatusChanged;
use App\Jobs\DispatchWebhook;
use App\Listeners\Webhook\SendPostCreatedWebhook;
use App\Listeners\Webhook\SendPostDeletedWebhook;
use App\Listeners\Webhook\SendPostStatusWebhook;
use App\Models\Post;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([DispatchWebhook::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

/**
 * @param  list<EventType>  $events
 */
function subscribeToWebhook(Workspace $workspace, array $events): Webhook
{
    return Webhook::factory()->create([
        'workspace_id' => $workspace->id,
        'events' => array_map(fn (EventType $event): string => $event->value, $events),
    ]);
}

test('webhook listeners are registered for the post lifecycle events', function () {
    Event::fake();

    Event::assertListening(PostCreated::class, SendPostCreatedWebhook::class);
    Event::assertListening(PostStatusChanged::class, SendPostStatusWebhook::class);
    Event::assertListening(PostDeleted::class, SendPostDeletedWebhook::class);
});

test('creating a post through CreatePost queues a post.created webhook', function () {
    subscribeToWebhook($this->workspace, [EventType::PostCreated]);

    $post = CreatePost::execute($this->workspace, $this->user, [
        'content' => 'Hello from webhooks',
        'created_via' => CreatedVia::Web,
    ]);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post) {
        return $job->eventType === EventType::PostCreated->value
            && data_get($job->payload, 'id') === $post->id
            && data_get($job->payload, 'workspace_id') === $this->workspace->id
            && data_get($job->payload, 'status') === PostStatus::Draft->value;
    });
});

test('creating a draft does not queue status webhooks', function () {
    subscribeToWebhook($this->workspace, EventType::cases());

    CreatePost::execute($this->workspace, $this->user, [
        'content' => 'Draft only',
        'created_via' => CreatedVia::Web,
    ]);

    Queue::assertPushed(DispatchWebhook::class, 1);
    Queue::assertPushed(DispatchWebhook::class, fn (DispatchWebhook $job) => $job->eventType === EventType::PostCreated->value);
});

test('changing post status through the observer queues the matching webhook', function (PostStatus $status, EventType $event) {
    subscribeToWebhook($this->workspace, [$event]);

    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    match ($status) {
        PostStatus::Scheduled => $post->update([
            'status' => PostStatus::Scheduled,
            'scheduled_at' => now()->addDay(),
        ]),
        PostStatus::Published => $post->markAsPublished(),
        PostStatus::PartiallyPublished => $post->markAsPartiallyPublished(),
        PostStatus::Failed => $post->markAsFailed(),
        default => $post->update(['status' => $status]),
    };

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post, $event) {
        return $job->eventType === $event->value
            && data_get($job->payload, 'id') === $post->id;
    });
})->with([
    [PostStatus::Scheduled, EventType::PostScheduled],
    [PostStatus::Published, EventType::PostPublished],
    [PostStatus::PartiallyPublished, EventType::PostPartiallyPublished],
    [PostStatus::Failed, EventType::PostFailed],
]);

test('marking a post as publishing does not queue a webhook', function () {
    subscribeToWebhook($this->workspace, EventType::cases());

    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $post->markAsPublishing();

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('deleting a post through DeletePost queues a post.deleted webhook', function () {
    subscribeToWebhook($this->workspace, [EventType::PostDeleted]);

    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    DeletePost::execute($post);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post) {
        return $job->eventType === EventType::PostDeleted->value
            && $job->payload === [
                'id' => $post->id,
                'workspace_id' => $this->workspace->id,
            ];
    });
});

test('a paused webhook is not queued when a post is created', function () {
    Webhook::factory()->paused()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostCreated->value],
    ]);

    CreatePost::execute($this->workspace, $this->user, [
        'content' => 'Should not notify',
        'created_via' => CreatedVia::Web,
    ]);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('a disabled webhook is not queued when a post is created', function () {
    Webhook::factory()->disabled()->create([
        'workspace_id' => $this->workspace->id,
        'events' => [EventType::PostCreated->value],
    ]);

    CreatePost::execute($this->workspace, $this->user, [
        'content' => 'Should not notify',
        'created_via' => CreatedVia::Web,
    ]);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('a webhook subscribed only to other events is not queued on create', function () {
    subscribeToWebhook($this->workspace, [EventType::PostPublished, EventType::PostFailed]);

    CreatePost::execute($this->workspace, $this->user, [
        'content' => 'Created but unpublished',
        'created_via' => CreatedVia::Web,
    ]);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('a webhook from another workspace is not queued', function () {
    $otherWorkspace = Workspace::factory()->create();
    subscribeToWebhook($otherWorkspace, EventType::cases());

    CreatePost::execute($this->workspace, $this->user, [
        'content' => 'Other workspace should not see this',
        'created_via' => CreatedVia::Web,
    ]);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('creating a post through the observer without Event::fake still queues the job', function () {
    subscribeToWebhook($this->workspace, [EventType::PostCreated]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post) {
        return $job->eventType === EventType::PostCreated->value
            && data_get($job->payload, 'id') === $post->id;
    });
});
