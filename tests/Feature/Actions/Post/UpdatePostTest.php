<?php

declare(strict_types=1);

use App\Actions\Post\CreatePosts;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\PublishStatus as PlatformStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\GatedFeature;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('execute rejects a platforms list from a stale client', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->scheduled()->create([
        'user_id' => $user->id,
        'content' => 'Original',
    ]);

    expect(fn () => UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'content' => 'Edited',
        'platforms' => [],
    ], $user))->toThrow(ValidationException::class);

    expect($post->fresh()->content)->toBe('Original')
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('a draft without a channel keeps its edits but cannot be scheduled', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Original',
    ]);

    UpdatePost::execute($workspace, $post, ['status' => PostStatus::Draft->value, 'content' => 'Edited'], $user);

    expect($post->fresh()->content)->toBe('Edited')
        ->and($post->fresh()->status)->toBe(PostStatus::Draft);

    expect(fn () => UpdatePost::execute($workspace, $post->fresh(), [
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ], $user))->toThrow(ValidationException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('execute keeps the google business jpeg on an edit', function () {
    Storage::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->state(['workspace_id' => $workspace->id])->googleBusiness()->pendingReview()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->addDay(),
        'content' => 'Current promotion',
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($post);
    Storage::put($path, 'image');

    UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'content' => 'Updated promotion',
    ], $user);

    expect($post->fresh()->publish_status)->toBe(PlatformStatus::PendingReview);
    Storage::assertExists($path);
});

test('invalid publication metadata rolls the edit back', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->state(['workspace_id' => $workspace->id])->youtube()->create([
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'Original',
        'meta' => ['description' => str_repeat('a', 5001)],
    ]);

    expect(fn () => UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
        'content' => 'Edited',
    ], $user))->toThrow(ValidationException::class);

    expect($post->fresh()->status)->toBe(PostStatus::Draft)
        ->and($post->fresh()->content)->toBe('Original');
});

test('an edit does not land on a post the scheduler claimed after it was loaded', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'scheduled',
        'scheduled_at' => now()->addHour()->toIso8601String(),
        'content' => 'Original',
        'destinations' => [['social_account_id' => $account->id]],
    ])->sole();

    Post::query()->whereKey($post->id)->update(['status' => PostStatus::Publishing]);

    $result = UpdatePost::execute($workspace, $post, ['status' => 'draft', 'content' => 'Edited'], $user);

    expect($result['action'])->toBe(PostAction::Finalized)
        ->and($post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($post->fresh()->content)->toBe('Original');
});

test('a second publish now after the post settled is a no-op', function () {
    Queue::fake();
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = CreatePosts::execute($workspace, $user, [
        'status' => 'draft',
        'content' => 'Original',
        'destinations' => [['social_account_id' => $account->id]],
    ])->sole();

    Post::query()->whereKey($post->id)->update(['status' => PostStatus::Published]);

    $result = UpdatePost::execute($workspace, $post, ['status' => 'publishing'], $user);

    expect($result['action'])->toBe(PostAction::Finalized)
        ->and($post->fresh()->status)->toBe(PostStatus::Published);
    Queue::assertNotPushed(PublishPost::class);
});

test('updating a content warning preserves a reply saved after the post was loaded', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->mastodon()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->draft()->create(['user_id' => $user->id, 'content' => 'Caption', 'meta' => []])->fresh();
    $post->fresh()->update(['meta' => ['thread_replies' => [['text' => 'Concurrent reply', 'media' => []]]]]);
    UpdatePost::execute($workspace, $post, ['meta' => ['spoiler_text' => 'Warning']], $user);
    expect($post->fresh()->meta)->toMatchArray(['spoiler_text' => 'Warning'])
        ->and(data_get($post->fresh()->meta, 'thread_replies.0.text'))->toBe('Concurrent reply');
});

test('scheduling rechecks the permission needed by a reply added after the post was loaded', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->mastodon()->create(['workspace_id' => $workspace->id]);
    gateFeature($account->platform, GatedFeature::ThreadReplies, ['write:replies']);
    $post = Post::factory()->forAccount($account)->draft()->create(['user_id' => $user->id, 'content' => 'Caption'])->fresh();
    $post->fresh()->update(['meta' => ['thread_replies' => [['text' => 'Concurrent reply', 'media' => []]]]]);

    expect(fn () => UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addDay()->toIso8601String(),
    ], $user))->toThrow(ValidationException::class)
        ->and($post->fresh()->status)->toBe(PostStatus::Draft)
        ->and(data_get($post->fresh()->meta, 'thread_replies.0.text'))->toBe('Concurrent reply');
});
