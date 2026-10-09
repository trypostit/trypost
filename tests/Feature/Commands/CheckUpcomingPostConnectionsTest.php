<?php

declare(strict_types=1);

use App\Jobs\VerifyUpcomingPostConnections;
use App\Models\Post;
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
        $post = Post::factory()->forAccount($account)->scheduled()->create([
            'scheduled_at' => now()->addMinutes(10 * $i),
        ]);
    }

    $this->artisan('social:check-upcoming-connections')
        ->assertSuccessful();

    Queue::assertPushed(VerifyUpcomingPostConnections::class, fn ($job) => $job->workspaceId === $workspace->id);
});

test('dispatches nothing when no workspace has posts in the window', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addHours(5),
    ]);

    $this->artisan('social:check-upcoming-connections')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches nothing when the only at-risk post was already warned today', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'scheduled_at' => now()->addMinutes(30),
        'connection_warning_sent_at' => now()->subHours(2),
    ]);

    $this->artisan('social:check-upcoming-connections')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('dispatches nothing when the only at-risk post is still a draft', function () {
    Event::fake();
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->draft()->create([
        'scheduled_at' => now()->addMinutes(30),
    ]);

    $this->artisan('social:check-upcoming-connections')
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
        $post = Post::factory()->forAccount($account)->scheduled()->create([
            'scheduled_at' => now()->addMinutes(30),
        ]);
    }

    $this->artisan('social:check-upcoming-connections')->assertSuccessful();

    Queue::assertPushed(VerifyUpcomingPostConnections::class, 2);
});
