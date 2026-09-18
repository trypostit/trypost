<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Events\PostCreated;
use App\Jobs\PostHog\TrackPost;
use App\Listeners\PostHog\TrackPostCreated;
use App\Models\Account;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

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

test('listener dispatches TrackPost with the post id', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'created_via' => CreatedVia::Web,
    ]);

    Bus::fake();

    (new TrackPostCreated)->handle(new PostCreated($post));

    Bus::assertDispatched(
        TrackPost::class,
        fn ($job) => $job->postId === (string) $post->id,
    );
});

test('listener is wired to the PostCreated event via auto-discovery', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'created_via' => CreatedVia::Api,
    ]);

    Bus::fake();

    PostCreated::dispatch($post);

    Bus::assertDispatched(TrackPost::class);
});

test('listener does not dispatch when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Bus::fake();

    (new TrackPostCreated)->handle(new PostCreated($post));

    Bus::assertNotDispatched(TrackPost::class);
});
