<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\PublishPost;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->discordAccount = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '111222333',
    ]);
});

test('create post persists Discord channel + embeds meta', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Hello Discord',
            'platforms' => [[
                'social_account_id' => $this->discordAccount->id,
                'content_type' => ContentType::DiscordMessage->value,
                'meta' => [
                    'channel_id' => '444555666',
                    'embeds' => [['title' => 'Release']],
                ],
            ]],
        ]);

    $response->assertOk();

    $meta = PostPlatform::where('social_account_id', $this->discordAccount->id)->sole()->meta;

    expect($meta['channel_id'])->toBe('444555666')
        ->and(data_get($meta, 'embeds.0.title'))->toBe('Release');
});

test('create post persists LinkedIn document_title meta', function () {
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Check our latest deck',
            'platforms' => [[
                'social_account_id' => $linkedin->id,
                'content_type' => ContentType::LinkedInPost->value,
                'meta' => ['document_title' => 'Q2 Report'],
            ]],
        ]);

    $response->assertOk();

    expect(PostPlatform::where('social_account_id', $linkedin->id)->sole()->meta['document_title'])->toBe('Q2 Report');
});

test('update post merges per-platform meta', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->discord()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->discordAccount->id,
        'enabled' => true,
        'meta' => ['channel_name' => 'general'],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => ['channel_id' => '444555666'],
            ]],
        ]);

    $response->assertOk();

    $meta = $platform->fresh()->meta;
    expect($meta['channel_id'])->toBe('444555666')
        ->and($meta['channel_name'])->toBe('general'); // merged, not overwritten
});

test('publish guard ignores disabled platforms missing meta', function () {
    Queue::fake();

    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $linkedin->id,
        'enabled' => true,
    ]);
    // Disabled Discord with no channel must not block the publish.
    PostPlatform::factory()->discord()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->discordAccount->id,
        'enabled' => false,
        'meta' => [],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertOk();
    Queue::assertPushed(PublishPost::class);
});

test('publish post rejects a Discord platform without a channel', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->discord()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->discordAccount->id,
        'enabled' => true,
        'meta' => [],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.discord.channel_required')]);
});

test('publish guard enforces required meta for TikTok and Pinterest', function (string $factoryState, string $field, string $messageKey) {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => $factoryState === 'tiktok' ? Platform::TikTok : Platform::Pinterest,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->{$factoryState}()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
        'meta' => [],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__($messageKey)]);
})->with([
    'tiktok' => ['tiktok', 'privacy_level', 'posts.form.tiktok.privacy_required'],
    'pinterest' => ['pinterest', 'board_id', 'posts.form.pinterest.board_required'],
]);

test('attach media from upload accepts a PDF for a LinkedIn post', function () {
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $linkedin->id,
        'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost, 'enabled' => true,
    ]);

    $uploadToken = (string) Str::uuid();
    $this->workspace->media()->create([
        'group_id' => (string) Str::uuid(),
        'collection' => 'assets',
        'type' => 'document',
        'path' => 'medias/deck.pdf',
        'original_filename' => 'deck.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1000,
        'order' => 0,
        'upload_token' => $uploadToken,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $post->id,
            'upload_token' => $uploadToken,
        ]);

    $response->assertOk();

    $media = $post->fresh()->media;
    expect($media)->toHaveCount(1)
        ->and($media[0]['type'])->toBe('document')
        ->and($media[0]['mime_type'])->toBe('application/pdf');
});

test('publish post succeeds for a LinkedIn document that has a PDF', function () {
    Queue::fake();

    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'media' => [[
            'id' => 'doc-1', 'path' => 'medias/deck.pdf', 'url' => 'https://example.com/deck.pdf',
            'type' => 'document', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf',
        ]],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $linkedin->id,
        'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost, 'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertOk();
    Queue::assertPushed(PublishPost::class);
});

test('publish post rejects a LinkedIn post that mixes a PDF with an image', function () {
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'media' => [
            ['id' => 'doc-1', 'path' => 'medias/deck.pdf', 'url' => 'https://example.com/deck.pdf', 'type' => 'document', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf'],
            ['id' => 'img-1', 'path' => 'medias/slide.jpg', 'url' => 'https://example.com/slide.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg', 'original_filename' => 'slide.jpg'],
        ],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $linkedin->id,
        'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost, 'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors(['A PDF document must be the only attachment.']);
});

test('publish post succeeds for a Discord platform with a channel', function () {
    Queue::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->discord()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->discordAccount->id,
        'enabled' => true,
        'meta' => ['channel_id' => '444555666'],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertOk();
    Queue::assertPushed(PublishPost::class);
});

test('create post persists Pinterest title and link meta', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Shared caption',
            'platforms' => [[
                'social_account_id' => $pinterest->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => [
                    'board_id' => 'board-1',
                    'title' => 'Pin Title',
                    'link' => 'https://example.com/product',
                ],
            ]],
        ]);

    $response->assertOk();

    $meta = PostPlatform::where('social_account_id', $pinterest->id)->sole()->meta;

    expect(data_get($meta, 'title'))->toBe('Pin Title')
        ->and(data_get($meta, 'link'))->toBe('https://example.com/product')
        ->and(array_key_exists('description', $meta))->toBeFalse();
});

test('update post merges Pinterest title and link meta', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Shared caption',
    ]);
    $platform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $post->id,
        'social_account_id' => $pinterest->id,
        'enabled' => true,
        'meta' => ['board_id' => 'board-1'],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => [
                    'board_id' => 'board-1',
                    'title' => 'Updated Title',
                    'link' => 'https://example.com/updated',
                ],
            ]],
        ]);

    $response->assertOk();

    $meta = $platform->fresh()->meta;

    expect(data_get($meta, 'title'))->toBe('Updated Title')
        ->and(data_get($meta, 'link'))->toBe('https://example.com/updated')
        ->and(data_get($meta, 'board_id'))->toBe('board-1');
});

test('create post rejects invalid Pinterest destination link', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Shared caption',
            'platforms' => [[
                'social_account_id' => $pinterest->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => [
                    'board_id' => 'board-1',
                    'link' => 'ftp://files.example.com/pin',
                ],
            ]],
        ]);

    $response->assertHasErrors();
});

test('create post persists Instagram collaborators', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Collab reel',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramReel->value,
                'meta' => ['collaborators' => ['@Host_One', 'host_two']],
            ]],
        ]);

    $response->assertOk();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta)
        ->toMatchArray([
            'collaborators' => ['Host_One', 'host_two'],
        ]);
});

test('create post rejects Instagram collaborators sent as a comma-separated string', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Collab reel',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramReel->value,
                'meta' => ['collaborators' => 'Host_One,host_two'],
            ]],
        ])
        ->assertHasErrors();
});

test('create post persists Instagram Facebook collaborators', function () {
    $instagram = SocialAccount::factory()->instagramFacebook()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Collab reel',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramReel->value,
                'meta' => ['collaborators' => ['@Host_One']],
            ]],
        ]);

    $response->assertOk();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta['collaborators'])
        ->toBe(['Host_One']);
});

test('create post rejects more than three Instagram collaborators', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Too many',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['a', 'b', 'c', 'd']],
            ]],
        ])
        ->assertHasErrors();
});

test('create post rejects an invalid Instagram collaborator username', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Bad username',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['.user']],
            ]],
        ])
        ->assertHasErrors();
});

test('create post clears Instagram collaborators on a story', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Story',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramStory->value,
                'meta' => ['collaborators' => ['@Host_One']],
            ]],
        ]);

    $response->assertOk();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta['collaborators'])
        ->toBe([]);
});

test('create post rejects tagging the connected Instagram account as a collaborator', function () {
    $instagram = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'testuser',
    ]);

    TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Self collab',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['testuser']],
            ]],
        ])
        ->assertHasErrors();
});

test('update post merges Instagram collaborators with existing aspect_ratio', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram,
        'enabled' => true,
        'meta' => ['aspect_ratio' => '4:5'],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => ['collaborators' => ['host_one']],
            ]],
        ]);

    $response->assertOk();

    $meta = $platform->fresh()->meta;

    expect(data_get($meta, 'collaborators'))->toBe(['host_one'])
        ->and(data_get($meta, 'aspect_ratio'))->toBe('4:5');
});

test('update post clears Instagram collaborators when switching to a story without sending meta', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramReel,
        'enabled' => true,
        'meta' => ['collaborators' => ['Host_One'], 'aspect_ratio' => '4:5'],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [[
                'id' => $platform->id,
                'content_type' => ContentType::InstagramStory->value,
            ]],
        ]);

    $response->assertOk();

    $meta = $platform->fresh()->meta;

    expect(data_get($meta, 'collaborators'))->toBe([])
        ->and(data_get($meta, 'aspect_ratio'))->toBe('4:5')
        ->and($platform->fresh()->content_type)->toBe(ContentType::InstagramStory);
});

test('update post clears leftover Instagram collaborators on an already-story row without sending content_type', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramStory,
        'enabled' => true,
        'meta' => ['collaborators' => ['Host_One'], 'aspect_ratio' => '4:5'],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'platforms' => [[
                'id' => $platform->id,
            ]],
        ]);

    $response->assertOk();

    $meta = $platform->fresh()->meta;

    expect(data_get($meta, 'collaborators'))->toBe([])
        ->and(data_get($meta, 'aspect_ratio'))->toBe('4:5')
        ->and($platform->fresh()->content_type)->toBe(ContentType::InstagramStory);
});
