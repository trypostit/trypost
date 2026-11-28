<?php

declare(strict_types=1);

use App\Actions\Post\DeletePost;
use App\Enums\Post\Status as PostStatus;
use App\Events\PostDeleted;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

test('execute deletes the post', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    DeletePost::execute($post);

    expect(Post::find($post->id))->toBeNull();
});

test('execute dispatches PostDeleted with the post and workspace ids', function () {
    Event::fake([PostDeleted::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $postId = $post->id;
    $workspaceId = $post->workspace_id;

    DeletePost::execute($post);

    Event::assertDispatched(
        PostDeleted::class,
        fn (PostDeleted $event) => $event->postId === $postId
            && $event->workspaceId === $workspaceId,
    );
});

test('execute prunes a google business jpeg still waiting on review', function () {
    Storage::fake();

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $account = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $workspace->id,
    ]);
    $post = Post::factory()->forAccount($account)->googleBusiness()->pendingReview()->create([
        'user_id' => $user->id,
        'platform_post_id' => 'accounts/1/locations/2/localPosts/3',
    ]);
    $path = GoogleBusinessDerivativeCleaner::pathFor($post);
    Storage::put($path, 'image');

    DeletePost::execute($post);

    expect(Post::find($post->id))->toBeNull();
    Storage::assertMissing($path);
});

test('a post claimed for publishing after it was loaded is not deleted', function () {
    Event::fake([PostDeleted::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Post::query()->whereKey($post->id)->update(['status' => PostStatus::Publishing]);

    expect(fn () => DeletePost::execute($post, respectStatus: true))->toThrow(ValidationException::class);

    expect(Post::query()->find($post->id))->not->toBeNull();
    Event::assertNotDispatched(PostDeleted::class);
});
