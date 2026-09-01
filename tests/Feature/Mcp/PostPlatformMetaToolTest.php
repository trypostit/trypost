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

test('create post persists Google Business topic_type and offer meta', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Big sale this week',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'topic_type' => 'OFFER',
                    'offer' => ['coupon_code' => 'SAVE10'],
                ],
            ]],
        ]);

    $response->assertOk();

    $meta = PostPlatform::where('social_account_id', $googleBusiness->id)->sole()->meta;

    expect(data_get($meta, 'topic_type'))->toBe('OFFER')
        ->and(data_get($meta, 'offer.coupon_code'))->toBe('SAVE10');
});

test('create post persists Google Business call_to_action meta', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Book a table tonight',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'call_to_action' => ['action_type' => 'BOOK', 'url' => 'https://example.com'],
                ],
            ]],
        ]);

    $response->assertOk();

    $meta = PostPlatform::where('social_account_id', $googleBusiness->id)->sole()->meta;

    expect(data_get($meta, 'call_to_action.action_type'))->toBe('BOOK')
        ->and(data_get($meta, 'call_to_action.url'))->toBe('https://example.com');
});

test('create post persists Google Business event time meta', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Grand opening',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'topic_type' => 'EVENT',
                    'event' => [
                        'title' => 'Grand Opening',
                        'start_date' => '2026-09-01',
                        'end_date' => '2026-09-02',
                        'start_time' => '09:00',
                        'end_time' => '17:00',
                    ],
                ],
            ]],
        ]);

    $response->assertOk();

    $meta = PostPlatform::where('social_account_id', $googleBusiness->id)->sole()->meta;

    expect(data_get($meta, 'event.title'))->toBe('Grand Opening')
        ->and(data_get($meta, 'event.start_date'))->toBe('2026-09-01')
        ->and(data_get($meta, 'event.end_date'))->toBe('2026-09-02')
        ->and(data_get($meta, 'event.start_time'))->toBe('09:00')
        ->and(data_get($meta, 'event.end_time'))->toBe('17:00');
});

test('create post persists Google Business offer redeem url and terms meta', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Big sale this week',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'offer' => [
                        'coupon_code' => 'SAVE10',
                        'redeem_online_url' => 'https://example.com/redeem',
                        'terms_conditions' => 'Some terms',
                    ],
                ],
            ]],
        ]);

    $response->assertOk();

    $meta = PostPlatform::where('social_account_id', $googleBusiness->id)->sole()->meta;

    expect(data_get($meta, 'offer.coupon_code'))->toBe('SAVE10')
        ->and(data_get($meta, 'offer.redeem_online_url'))->toBe('https://example.com/redeem')
        ->and(data_get($meta, 'offer.terms_conditions'))->toBe('Some terms');
});

test('publish post rejects a Google Business event post without event fields', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id,
        'social_account_id' => $googleBusiness->id,
        'enabled' => true,
        'meta' => ['topic_type' => 'EVENT'],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.google_business.event_title_required')]);
});
