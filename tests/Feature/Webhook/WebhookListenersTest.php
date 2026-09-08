<?php

declare(strict_types=1);

use App\Actions\Post\DeletePost;
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

    Webhook::factory()->create([
        'workspace_id' => $this->workspace->id,
        'events' => array_column(EventType::cases(), 'value'),
    ]);
});

test('SendPostCreatedWebhook dispatches a webhook', function () {
    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    app(SendPostCreatedWebhook::class)->handle(new PostCreated($post));

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post) {
        return $job->eventType === EventType::PostCreated->value
            && data_get($job->payload, 'id') === $post->id;
    });
});

test('SendPostDeletedWebhook dispatches a webhook with ids only', function () {
    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    app(SendPostDeletedWebhook::class)->handle(new PostDeleted($post->id, $this->workspace->id));

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post) {
        return $job->eventType === EventType::PostDeleted->value
            && $job->payload === [
                'id' => $post->id,
                'workspace_id' => $this->workspace->id,
            ];
    });
});

test('SendPostDeletedWebhook skips when workspace is missing', function () {
    app(SendPostDeletedWebhook::class)->handle(new PostDeleted(
        '00000000-0000-4000-a000-000000000000',
        '00000000-0000-4000-a000-000000000000',
    ));

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('SendPostStatusWebhook dispatches for known post statuses', function (PostStatus $status, EventType $event) {
    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => $status,
    ]);

    app(SendPostStatusWebhook::class)->handle(new PostStatusChanged($post));

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($event) {
        return $job->eventType === $event->value;
    });
})->with([
    [PostStatus::Scheduled, EventType::PostScheduled],
    [PostStatus::Published, EventType::PostPublished],
    [PostStatus::PartiallyPublished, EventType::PostPartiallyPublished],
    [PostStatus::Failed, EventType::PostFailed],
]);

test('SendPostStatusWebhook skips draft status', function () {
    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    app(SendPostStatusWebhook::class)->handle(new PostStatusChanged($post));

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('SendPostStatusWebhook dispatches post.unscheduled when a scheduled post becomes a draft', function () {
    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    app(SendPostStatusWebhook::class)->handle(new PostStatusChanged($post, PostStatus::Scheduled));

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) {
        return $job->eventType === EventType::PostUnscheduled->value;
    });
});

test('SendPostStatusWebhook skips a draft that did not come from scheduled', function () {
    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    app(SendPostStatusWebhook::class)->handle(new PostStatusChanged($post, PostStatus::Failed));

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('creating a post dispatches PostCreated via the observer', function () {
    Event::fake([PostCreated::class]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Event::assertDispatched(
        PostCreated::class,
        fn (PostCreated $event) => $event->post->id === $post->id,
    );
});

test('changing post status dispatches PostStatusChanged via the observer', function () {
    Event::fake([PostStatusChanged::class]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    Event::fake([PostStatusChanged::class]);

    $post->update(['status' => PostStatus::Published]);

    Event::assertDispatched(
        PostStatusChanged::class,
        fn (PostStatusChanged $event) => $event->post->id === $post->id,
    );
});

test('unscheduling dispatches PostStatusChanged with the previous scheduled status', function () {
    $post = Post::factory()->scheduled()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Event::fake([PostStatusChanged::class]);

    $post->update(['status' => PostStatus::Draft]);

    Event::assertDispatched(
        PostStatusChanged::class,
        fn (PostStatusChanged $event) => $event->post->id === $post->id
            && $event->previousStatus === PostStatus::Scheduled,
    );
});

test('deleting a post dispatches the deleted webhook', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    DeletePost::execute($post);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post) {
        return $job->eventType === EventType::PostDeleted->value
            && data_get($job->payload, 'id') === $post->id;
    });
});

test('post created listener is wired via auto-discovery', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    PostCreated::dispatch($post);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) {
        return $job->eventType === EventType::PostCreated->value;
    });
});
