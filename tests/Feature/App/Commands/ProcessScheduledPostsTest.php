<?php

declare(strict_types=1);

use App\Console\Commands\ProcessScheduledPosts;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

test('process scheduled posts dispatches publish job for due posts', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $duePost = Post::factory()->forAccount($socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(PublishPost::class, function ($job) use ($duePost) {
        return $job->post->id === $duePost->id;
    });
});

test('process scheduled posts does not dispatch for future posts', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $futurePost = Post::factory()->forAccount($socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertNotPushed(PublishPost::class);
});

test('process scheduled posts does not dispatch for draft posts', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $draftPost = Post::factory()->forAccount($socialAccount)->draft()->create([
        'user_id' => $this->user->id,
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertNotPushed(PublishPost::class);
});

test('process scheduled posts handles multiple due posts', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $posts = Post::factory()->forAccount($socialAccount)->count(3)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(PublishPost::class, 3);
});

test('a failure claiming one due post does not stop the rest of the tick', function () {
    Queue::fake();
    Exceptions::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    $posts = collect(range(1, 3))->map(function () use ($socialAccount) {
        $post = Post::factory()->forAccount($socialAccount)->create([
            'user_id' => $this->user->id,
            'status' => PostStatus::Scheduled,
            'scheduled_at' => now()->subMinute(),
        ]);

        return $post;
    });

    $failed = false;
    DB::beforeExecuting(function (string $query) use (&$failed) {
        if (! $failed && str_starts_with($query, 'update') && str_contains($query, 'posts')) {
            $failed = true;

            throw new RuntimeException('Lock wait timeout exceeded');
        }
    });

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(PublishPost::class, 2);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Lock wait timeout exceeded');
    expect($posts->filter(fn (Post $post) => $post->fresh()->status === PostStatus::Scheduled))->toHaveCount(1);
});
