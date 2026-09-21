<?php

declare(strict_types=1);

use App\Actions\Post\DeletePost;
use App\Events\PostDeleted;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

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
    $post = Post::factory()->create([
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

    DeletePost::execute($post);

    expect(Post::find($post->id))->toBeNull();
    Storage::assertMissing($path);
});
