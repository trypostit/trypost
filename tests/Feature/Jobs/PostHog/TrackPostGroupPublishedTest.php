<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Enums\PostHog\PostEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackPostGroupPublished;
use App\Models\Account;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
});

test('job is queued on the posthog queue', function () {
    expect((new TrackPostGroupPublished((string) Str::uuid()))->queue)->toBe('posthog');
});

test('group of three publishes one event', function () {
    Queue::fake();

    $groupId = (string) Str::uuid();
    $publishedAt = now()->subMinute()->startOfSecond();
    $posts = collect(['linkedin', 'x', 'facebook'])->map(fn (string $network) => Post::factory()
        ->{$network}()
        ->published()
        ->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'post_group_id' => $groupId,
            'created_via' => CreatedVia::Web,
            'published_at' => $publishedAt,
        ]));

    $posts->shuffle()->each(fn (Post $post) => (new TrackPostGroupPublished((string) $post->id))->handle(app(PostHogService::class)));

    Queue::assertPushed(SendEvent::class, 1);
    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['event'] === PostEvent::Published->value
        && $job->payload['distinctId'] === (string) $this->user->id
        && $job->payload['properties']['post_group_id'] === $groupId
        && $job->payload['properties']['created_via'] === CreatedVia::Web->value
        && $job->payload['properties']['channels_count'] === 3
        && $job->payload['properties']['$groups']['account'] === (string) $this->account->id);
});

test('only the first published post of the group emits the event', function () {
    Queue::fake();

    $groupId = (string) Str::uuid();
    $first = Post::factory()->linkedin()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'post_group_id' => $groupId,
        'published_at' => now()->subMinutes(2),
    ]);
    $second = Post::factory()->x()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'post_group_id' => $groupId,
        'published_at' => now()->subMinute(),
    ]);

    (new TrackPostGroupPublished((string) $second->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);

    (new TrackPostGroupPublished((string) $first->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, 1);
});

test('platforms lists the networks that published', function () {
    Queue::fake();

    $groupId = (string) Str::uuid();
    $attributes = [
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'post_group_id' => $groupId,
    ];
    $first = Post::factory()->linkedin()->published()->create([...$attributes, 'published_at' => now()->subMinutes(3)]);
    $page = SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->forAccount($page)->published()->create([...$attributes, 'published_at' => now()->subMinutes(2)]);
    Post::factory()->x()->failed()->create($attributes);

    (new TrackPostGroupPublished((string) $first->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['properties']['platforms'] === ['linkedin']
        && $job->payload['properties']['channels_count'] === 3);
});

test('is_first is true for the account first group and false after', function () {
    Queue::fake();

    Post::factory()->linkedin()->imported()->create(['workspace_id' => $this->workspace->id]);

    $firstGroup = Post::factory()->linkedin()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'post_group_id' => (string) Str::uuid(),
    ]);

    (new TrackPostGroupPublished((string) $firstGroup->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['properties']['is_first'] === true);

    Queue::fake();

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $secondGroup = Post::factory()->x()->published()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
        'post_group_id' => (string) Str::uuid(),
    ]);

    (new TrackPostGroupPublished((string) $secondGroup->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['properties']['is_first'] === false);
});

test('handle falls back to the account owner when the post has no user', function () {
    Queue::fake();

    $post = Post::factory()->linkedin()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => null,
        'post_group_id' => (string) Str::uuid(),
    ]);

    (new TrackPostGroupPublished((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['distinctId'] === (string) $this->user->id);
});

test('handle does not push when the post is missing or PostHog is disabled', function () {
    Queue::fake();

    (new TrackPostGroupPublished((string) Str::uuid()))->handle(app(PostHogService::class));

    config(['services.posthog.api_key' => null]);

    $post = Post::factory()->linkedin()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    (new TrackPostGroupPublished((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});
