<?php

declare(strict_types=1);

use App\Actions\Post\CreatePost;
use App\Actions\Post\DeletePost;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\Webhook\EventType;
use App\Events\PostCreated;
use App\Events\PostDeleted;
use App\Events\PostStatusChanged;
use App\Jobs\DispatchWebhook;
use App\Listeners\Webhook\SendPostCreatedWebhook;
use App\Listeners\Webhook\SendPostDeletedWebhook;
use App\Listeners\Webhook\SendPostStatusWebhook;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Services\WebhookService;
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

test('creating a post through CreatePost includes labels and platforms in the payload', function () {
    subscribeToWebhook($this->workspace, [EventType::PostCreated]);

    $label = WorkspaceLabel::factory()->recycle($this->workspace)->create([
        'name' => 'Launch',
        'color' => '#7C3AED',
    ]);
    $account = SocialAccount::factory()->linkedin()->recycle($this->workspace)->create([
        'display_name' => 'Paulo Castellano',
        'username' => 'paulocastellano',
    ]);

    $post = CreatePost::execute($this->workspace, $this->user, [
        'content' => 'Hello from webhooks',
        'created_via' => CreatedVia::Web,
        'label_ids' => [$label->id],
        'platforms' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => ['document_title' => 'TryPost launch deck'],
        ]],
    ]);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post, $label, $account) {
        return $job->eventType === EventType::PostCreated->value
            && data_get($job->payload, 'id') === $post->id
            && data_get($job->payload, 'labels.0.id') === $label->id
            && data_get($job->payload, 'labels.0.name') === 'Launch'
            && data_get($job->payload, 'platforms.0.social_account_id') === $account->id
            && data_get($job->payload, 'platforms.0.enabled') === true
            && data_get($job->payload, 'platforms.0.meta.document_title') === 'TryPost launch deck';
    });
});

test('scheduling a post through UpdatePost includes labels and platforms from the same save', function () {
    subscribeToWebhook($this->workspace, [EventType::PostScheduled]);

    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Ready to schedule',
    ]);

    $label = WorkspaceLabel::factory()->recycle($this->workspace)->create([
        'name' => 'Launch',
        'color' => '#7C3AED',
    ]);
    $account = SocialAccount::factory()->linkedin()->recycle($this->workspace)->create();
    $platform = PostPlatform::factory()->recycle($post, $account)->create([
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'enabled' => false,
        'meta' => [],
    ]);

    UpdatePost::execute($this->workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'label_ids' => [$label->id],
        'platforms' => [[
            'id' => $platform->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => ['document_title' => 'TryPost launch deck'],
        ]],
    ]);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post, $label, $platform) {
        return $job->eventType === EventType::PostScheduled->value
            && data_get($job->payload, 'id') === $post->id
            && data_get($job->payload, 'status') === PostStatus::Scheduled->value
            && data_get($job->payload, 'labels.0.id') === $label->id
            && data_get($job->payload, 'labels.0.name') === 'Launch'
            && data_get($job->payload, 'platforms.0.id') === $platform->id
            && data_get($job->payload, 'platforms.0.enabled') === true
            && data_get($job->payload, 'platforms.0.meta.document_title') === 'TryPost launch deck';
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

test('unscheduling a post through UpdatePost queues a post.unscheduled webhook', function () {
    subscribeToWebhook($this->workspace, [EventType::PostUnscheduled]);

    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
        'content' => 'Was scheduled',
    ]);

    UpdatePost::execute($this->workspace, $post, [
        'status' => PostStatus::Draft->value,
    ]);

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($post) {
        return $job->eventType === EventType::PostUnscheduled->value
            && data_get($job->payload, 'id') === $post->id
            && data_get($job->payload, 'status') === PostStatus::Draft->value;
    });
});

test('saving a draft that was never scheduled does not queue post.unscheduled', function () {
    subscribeToWebhook($this->workspace, [EventType::PostUnscheduled]);

    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Still a draft',
    ]);

    UpdatePost::execute($this->workspace, $post, [
        'status' => PostStatus::Draft->value,
        'content' => 'Still a draft, edited',
    ]);

    Queue::assertNotPushed(DispatchWebhook::class);
});

test('moving a failed post back to draft does not queue post.unscheduled', function () {
    subscribeToWebhook($this->workspace, [EventType::PostUnscheduled]);

    $post = Post::factory()->failed()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $post->update(['status' => PostStatus::Draft]);

    Queue::assertNotPushed(DispatchWebhook::class);
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
            && data_get($job->payload, 'id') === $post->id
            && data_get($job->payload, 'author.id') === $this->user->id
            && data_get($job->payload, 'workspace.id') === $this->workspace->id
            && array_key_exists('labels', $job->payload)
            && array_key_exists('media', $job->payload)
            && array_key_exists('platforms', $job->payload);
    });
})->with([
    [PostStatus::Scheduled, EventType::PostScheduled],
    [PostStatus::Published, EventType::PostPublished],
    [PostStatus::PartiallyPublished, EventType::PostPartiallyPublished],
    [PostStatus::Failed, EventType::PostFailed],
]);

test('publishing a post queues the full webhook payload', function () {
    subscribeToWebhook($this->workspace, [EventType::PostPublished]);

    $post = Post::factory()->createQuietly([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
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
    $platform = PostPlatform::factory()->published()->recycle($post, $account)->create([
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'meta' => ['document_title' => 'TryPost launch deck'],
    ]);

    $post->markAsPublished();

    $expected = app(WebhookService::class)->postPayload($post->fresh());

    Queue::assertPushed(DispatchWebhook::class, function (DispatchWebhook $job) use ($expected, $platform) {
        return $job->eventType === EventType::PostPublished->value
            && $job->payload['id'] === $expected['id']
            && $job->payload['status'] === PostStatus::Published->value
            && $job->payload['author'] === $expected['author']
            && $job->payload['workspace'] === $expected['workspace']
            && $job->payload['labels'] === $expected['labels']
            && $job->payload['media'] === $expected['media']
            && $job->payload['platforms'] === $expected['platforms']
            && data_get($job->payload, 'platforms.0.id') === $platform->id;
    });
});

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
