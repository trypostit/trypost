<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Origin;
use App\Enums\PostHog\PostEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackPost;
use App\Models\Account;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    enablePostHog();

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
});

test('job is queued on the posthog queue', function () {
    $job = new TrackPost((string) Str::uuid());

    expect($job->queue)->toBe('posthog');
});

test('handle captures post.created with created_via and account group', function () {
    Queue::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'created_via' => CreatedVia::Api,
    ]);

    (new TrackPost((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) use ($post) {
        return $job->method === 'capture'
            && $job->payload['event'] === PostEvent::Created->value
            && $job->payload['distinctId'] === (string) $this->user->id
            && $job->payload['properties']['post_id'] === (string) $post->id
            && $job->payload['properties']['workspace_id'] === (string) $this->workspace->id
            && $job->payload['properties']['created_via'] === CreatedVia::Api->value
            && $job->payload['properties']['$groups']['account'] === (string) $this->account->id;
    });
});

test('handle returns silently when post does not exist', function () {
    Queue::fake();

    (new TrackPost((string) Str::uuid()))->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle does not push when PostHog is disabled', function () {
    config(['services.posthog.api_key' => null]);
    Queue::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'created_via' => CreatedVia::Web,
    ]);

    (new TrackPost((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});

test('handle tracks nothing for an imported network post or a post without an author', function (array $attributes) {
    Queue::fake();

    $post = Post::factory()->imported()->create([
        'workspace_id' => $this->workspace->id,
        ...$attributes,
    ]);

    (new TrackPost((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
})->with([
    'imported without an author' => fn () => [],
    'imported with an author' => fn () => ['user_id' => $this->user->id],
    'TryPost post without an author' => fn () => ['origin' => Origin::TryPost, 'user_id' => null],
]);
