<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\PostStatusRules;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
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

test('update post cannot turn a channel-less legacy draft into a channel post', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'content_type' => ContentType::LinkedInPost->value,
        ]);

    $response->assertHasErrors();

    expect($post->fresh()->social_account_id)->toBeNull()
        ->and($post->fresh()->content_type)->toBeNull();
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

    $response->assertHasErrors([__('posts.flash.cannot_edit_finalized')]);
})->with([
    PostStatus::Published,
    PostStatus::Failed,
    PostStatus::Publishing,
]);

test('update post rejects the removed platforms input', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Kept',
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'content' => 'Changed',
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => ContentType::LinkedInPost->value],
            ],
        ]);

    $response->assertHasErrors();

    expect($post->fresh()->content)->toBe('Kept');
});

test('update post rejects a content_type that does not match the post channel', function () {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'content_type' => 'x_post',
        ]);

    $response->assertHasErrors();

    expect($post->fresh()->content_type)->toBe(ContentType::LinkedInPost);
});

// PublishPostTool

test('publish post immediate dispatches PublishPost job', function () {
    Queue::fake();
    $this->freezeTime();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'scheduled_at' => null,
        'content' => 'Ready to publish',
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertOk();

    Queue::assertPushed(PublishPost::class);
    expect($post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($post->fresh()->scheduled_at->toDateTimeString())->toBe(now()->toDateTimeString());

    expect($post->fresh()->social_account_id)->toBe($this->socialAccount->id);
});

test('publish post scheduled does not dispatch immediately', function () {
    Queue::fake();

    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Ready to schedule',
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, [
            'post_id' => $post->id,
            'scheduled_at' => '2037-12-31T15:30:00Z',
        ]);

    $response->assertOk();

    Queue::assertNotPushed(PublishPost::class);
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('publish post fails for a post without a channel', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.errors.no_social_account')]);
});

test('publish post 404 from another workspace', function () {
    $other = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $other->id, 'user_id' => $this->user->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors(['Post not found.']);
});

test('publish post rejects posts already in a terminal state', function (PostStatus $status) {
    $post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'status' => $status,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.flash.cannot_edit_finalized')]);
})->with([
    PostStatus::Published,
    PostStatus::Failed,
    PostStatus::Publishing,
]);

test('the edit blocked message is translated text, not a lang key', function () {
    expect(PostStatusRules::editBlockedMessage())
        ->toBe(__('posts.flash.cannot_edit_finalized'))
        ->not->toBe('posts.flash.cannot_edit_finalized');
});
