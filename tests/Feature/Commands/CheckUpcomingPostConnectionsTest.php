<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Jobs\VerifyUpcomingPostConnections;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

test('dispatches the job once per workspace with at-risk posts, even with multiple posts', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);

    foreach (range(1, 5) as $i) {
        $post = Post::factory()->scheduled()->create([
            'workspace_id' => $workspace->id,
            'scheduled_at' => now()->addMinutes(10 * $i),
        ]);
        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'status' => PostPlatformStatus::Pending,
        ]);
    }

    $this->artisan('social:check-upcoming-connections')
        ->expectsOutput('Dispatched 1 upcoming-post connection checks.')
        ->assertSuccessful();

    Queue::assertPushed(VerifyUpcomingPostConnections::class, fn ($job) => $job->workspaceId === $workspace->id);
});

test('dispatches nothing when no workspace has posts in the window', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addHours(5),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $this->artisan('social:check-upcoming-connections')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches nothing when the only at-risk post_platform was already warned today', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
        'connection_warning_sent_at' => now()->subHours(2),
    ]);

    $this->artisan('social:check-upcoming-connections')
        ->expectsOutput('Dispatched 0 upcoming-post connection checks.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches nothing when the only at-risk post_platform is disabled', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    PostPlatform::factory()->disabled()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $this->artisan('social:check-upcoming-connections')
        ->expectsOutput('Dispatched 0 upcoming-post connection checks.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches nothing when the only at-risk post_platform is on a paused account', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create([
        'workspace_id' => $workspace->id,
        'is_active' => false,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $this->artisan('social:check-upcoming-connections')
        ->expectsOutput('Dispatched 0 upcoming-post connection checks.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches nothing when the only at-risk post is still a draft', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->draft()->create([
        'workspace_id' => $workspace->id,
        'scheduled_at' => now()->addMinutes(30),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'status' => PostPlatformStatus::Pending,
    ]);

    $this->artisan('social:check-upcoming-connections')
        ->expectsOutput('Dispatched 0 upcoming-post connection checks.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches one job per distinct workspace when multiple workspaces have at-risk posts', function () {
    Event::fake();
    Queue::fake();

    $workspaceA = Workspace::factory()->create();
    $workspaceB = Workspace::factory()->create();

    foreach ([$workspaceA, $workspaceB] as $workspace) {
        $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
        $post = Post::factory()->scheduled()->create([
            'workspace_id' => $workspace->id,
            'scheduled_at' => now()->addMinutes(30),
        ]);
        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'status' => PostPlatformStatus::Pending,
        ]);
    }

    $this->artisan('social:check-upcoming-connections')->assertSuccessful();

    Queue::assertPushed(VerifyUpcomingPostConnections::class, 2);
});
