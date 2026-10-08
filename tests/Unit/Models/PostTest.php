<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

test('post belongs to workspace', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    expect($post->workspace->id)->toBe($this->workspace->id);
});

test('post belongs to user', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    expect($post->user->id)->toBe($this->user->id);
});

test('post belongs to its channel', function () {
    $socialAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->forAccount($socialAccount)->create([
        'user_id' => $this->user->id,
    ]);

    expect($post->socialAccount->id)->toBe($socialAccount->id)
        ->and($post->hasChannel())->toBeTrue()
        ->and($post->hasDestination())->toBeTrue();
});

test('a draft without a channel has no destination', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    expect($post->hasChannel())->toBeFalse()
        ->and($post->hasDestination())->toBeFalse();
});

test('post can be marked as publishing', function () {
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $post->markAsPublishing();

    expect($post->fresh()->status)->toBe(PostStatus::Publishing);
});

test('post can be marked as published', function () {
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $post->markAsPublished();

    expect($post->fresh()->status)->toBe(PostStatus::Published);
    expect($post->fresh()->published_at)->not->toBeNull();
});

test('post can be marked as failed', function () {
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $post->markAsFailed();

    expect($post->fresh()->status)->toBe(PostStatus::Failed);
});

test('post scope scheduled returns only scheduled posts', function () {
    Post::factory()->draft()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $scheduled = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $posts = Post::scheduled()->get();

    expect($posts)->toHaveCount(1);
    expect($posts->first()->id)->toBe($scheduled->id);
});

test('post scope due returns scheduled posts that are due', function () {
    Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => now()->addHour(),
    ]);

    $due = Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'scheduled_at' => now()->subMinute(),
    ]);

    $posts = Post::due()->get();

    expect($posts)->toHaveCount(1);
    expect($posts->first()->id)->toBe($due->id);
});

test('post scope draft returns only draft posts', function () {
    $draft = Post::factory()->draft()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Post::factory()->scheduled()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $posts = Post::draft()->get();

    expect($posts)->toHaveCount(1);
    expect($posts->first()->id)->toBe($draft->id);
});

test('post scope published returns only published posts', function () {
    Post::factory()->draft()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $published = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $posts = Post::published()->get();

    expect($posts)->toHaveCount(1);
    expect($posts->first()->id)->toBe($published->id);
});

test('post scope failed returns only failed posts', function () {
    Post::factory()->draft()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $failed = Post::factory()->failed()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $posts = Post::failed()->get();

    expect($posts)->toHaveCount(1);
    expect($posts->first()->id)->toBe($failed->id);
});
