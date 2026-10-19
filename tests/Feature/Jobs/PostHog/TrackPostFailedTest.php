<?php

declare(strict_types=1);

use App\Actions\Post\FinalizePostPublication;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status;
use App\Enums\PostHog\PostEvent;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackPostFailed;
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
    expect((new TrackPostFailed((string) Str::uuid()))->queue)->toBe('posthog');
});

test('failed post captures error_type from the error category', function () {
    Queue::fake();

    $groupId = (string) Str::uuid();
    $page = SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->forAccount($page)->failed()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'post_group_id' => $groupId,
        'created_via' => CreatedVia::Api,
        'error_context' => ['category' => ErrorCategory::RateLimit->value],
    ]);

    (new TrackPostFailed((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['event'] === PostEvent::Failed->value
        && $job->payload['distinctId'] === (string) $this->user->id
        && $job->payload['properties']['post_id'] === (string) $post->id
        && $job->payload['properties']['post_group_id'] === $groupId
        && $job->payload['properties']['platform'] === Platform::LinkedInPage->value
        && $job->payload['properties']['network'] === 'linkedin'
        && $job->payload['properties']['created_via'] === CreatedVia::Api->value
        && $job->payload['properties']['error_type'] === ErrorCategory::RateLimit->value
        && $job->payload['properties']['$groups']['account'] === (string) $this->account->id);
});

test('failed post without a category reports unknown', function () {
    Queue::fake();

    $post = Post::factory()->x()->failed()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => null,
    ]);

    (new TrackPostFailed((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['properties']['error_type'] === ErrorCategory::Unknown->value
        && $job->payload['distinctId'] === (string) $this->user->id);
});

test('handle does not push when PostHog is disabled', function () {
    config(['services.posthog.api_key' => null]);
    Queue::fake();

    $post = Post::factory()->x()->failed()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    (new TrackPostFailed((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});

test('a post failed for having no channel reports no platform or network', function () {
    Queue::fake();

    $post = Post::factory()->publishing()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    app(FinalizePostPublication::class)->handle($post);

    expect($post->fresh()->status)->toBe(Status::Failed);

    (new TrackPostFailed((string) $post->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->payload['event'] === PostEvent::Failed->value
        && $job->payload['properties']['post_id'] === (string) $post->id
        && $job->payload['properties']['platform'] === null
        && $job->payload['properties']['network'] === null);
});
