<?php

declare(strict_types=1);

use App\Actions\Post\UpdatePost;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('execute prunes a google business jpeg when the target is switched off', function () {
    Storage::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $linkedin = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $workspace->id,
        ])->id,
    ]);
    $target = PostPlatform::factory()->googleBusiness()->pendingReview()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->googleBusiness()->create([
            'workspace_id' => $workspace->id,
        ])->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($target->id);
    Storage::put($path, 'image');

    UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'platforms' => [['id' => $linkedin->id]],
    ]);

    expect($target->fresh()->enabled)->toBeFalse()
        ->and($target->fresh()->status)->toBe(PlatformStatus::Rejected)
        ->and($target->fresh()->error_message)->toBe(__('posts.errors.target_disabled'))
        ->and($linkedin->fresh()->enabled)->toBeTrue()
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
    Storage::assertMissing($path);
});

test('execute rejects switching off the sole social account', function () {
    Storage::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $target = PostPlatform::factory()->googleBusiness()->pendingReview()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->googleBusiness()->create([
            'workspace_id' => $workspace->id,
        ])->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($target->id);
    Storage::put($path, 'image');

    expect(fn () => UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'platforms' => [],
    ]))->toThrow(ValidationException::class);

    expect($target->fresh()->status)->toBe(PlatformStatus::PendingReview)
        ->and($target->fresh()->enabled)->toBeTrue()
        ->and($post->fresh()->status)->toBe(PostStatus::Scheduled);
    Storage::assertExists($path);
});

test('execute keeps the google business jpeg when the target stays enabled', function () {
    Storage::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $target = PostPlatform::factory()->googleBusiness()->pendingReview()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->googleBusiness()->create([
            'workspace_id' => $workspace->id,
        ])->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($target->id);
    Storage::put($path, 'image');

    UpdatePost::execute($workspace, $post, [
        'status' => PostStatus::Scheduled->value,
        'platforms' => [['id' => $target->id]],
    ]);

    expect($target->fresh()->enabled)->toBeTrue();
    Storage::assertExists($path);
});
