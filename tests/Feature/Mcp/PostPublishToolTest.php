<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\PublishPost;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

// UpdatePostTool

test('update post can change content', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'old',
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'content' => 'new content',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('content', 'new content')->etc();
        });

    expect($post->fresh()->content)->toBe('new content');
});

test('update post enables platforms', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $platform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => false,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [
                ['id' => $platform->id, 'content_type' => ContentType::LinkedInPost->value],
            ],
        ]);

    $response->assertOk();

    expect($platform->fresh()->enabled)->toBeTrue();
});

test('update post can attach labels', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'label_ids' => [$label->id],
        ]);

    $response->assertOk();
    expect($post->fresh()->labels()->pluck('id')->all())->toBe([$label->id]);
});

test('update post 404 from another workspace', function () {
    $other = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $other->id, 'user_id' => $this->user->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $post->id, 'content' => 'x']);

    $response->assertHasErrors(['Post not found.']);
});

test('update post rejects posts in any terminal state', function (PostStatus $status) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => $status,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, ['post_id' => $post->id, 'content' => 'x']);

    $response->assertHasErrors([__('posts.cannot_edit_finalized')]);
})->with([
    PostStatus::Published,
    PostStatus::PartiallyPublished,
    PostStatus::Failed,
    PostStatus::Publishing,
]);

test('update post rejects a platforms[].id that belongs to another post', function () {
    $myPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $otherPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $foreignPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $otherPost->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $myPost->id,
            'platforms' => [
                ['id' => $foreignPlatform->id, 'content_type' => ContentType::LinkedInPost->value],
            ],
        ]);

    $response->assertHasErrors();
});

test('update post rejects a content_type that does not match the post_platform', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $postPlatform = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [
                ['id' => $postPlatform->id, 'content_type' => 'x_post'],
            ],
        ]);

    $response->assertHasErrors();
});

// PublishPostTool

test('publish post immediate dispatches PublishPost job', function () {
    Queue::fake();
    $this->freezeTime();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
    ]);

    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertOk();

    Queue::assertPushed(PublishPost::class);
    expect($post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($post->fresh()->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());

    // Regression: previously UpdatePost::execute disabled every platform when
    // called without a `platforms` key, leaving the publish job with nothing
    // to publish. The Arr::has guard keeps the existing toggle state intact.
    expect(PostPlatform::where('post_id', $post->id)->where('enabled', true)->count())->toBe(1);
});

test('publish post scheduled does not dispatch immediately', function () {
    Queue::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, [
            'post_id' => $post->id,
            'scheduled_at' => '2099-12-31T15:30:00Z',
        ]);

    $response->assertOk();

    Queue::assertNotPushed(PublishPost::class);
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('publish post fails when no platforms enabled', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => false,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors(['Post has no enabled platforms. Use update-post-tool to enable at least one platform first.']);
});

test('publish post 404 from another workspace', function () {
    $other = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $other->id, 'user_id' => $this->user->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors(['Post not found.']);
});

test('publish post rejects posts already in a terminal state', function (PostStatus $status) {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => $status,
    ]);

    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.cannot_edit_finalized')]);
})->with([
    PostStatus::Published,
    PostStatus::PartiallyPublished,
    PostStatus::Failed,
    PostStatus::Publishing,
]);
