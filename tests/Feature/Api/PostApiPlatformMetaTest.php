<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];

    $this->headers = ['Authorization' => 'Bearer '.$this->plainToken];

    $this->discordAccount = SocialAccount::factory()->discord()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '111222333',
    ]);
});

it('persists Discord channel, mentions and embeds meta on store', function () {
    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Hello Discord',
            'platforms' => [[
                'social_account_id' => $this->discordAccount->id,
                'content_type' => ContentType::DiscordMessage->value,
                'meta' => [
                    'channel_id' => '444555666',
                    'mentions' => [['token' => '@everyone', 'label' => '@everyone']],
                    'embeds' => [['title' => 'Release', 'color' => '#5865F2']],
                ],
            ]],
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $this->discordAccount->id)->sole()->meta;

    // Assert nested keys survive validated() — the exact stripping bug this PR fixes.
    expect(data_get($meta, 'channel_id'))->toBe('444555666')
        ->and(data_get($meta, 'mentions.0.token'))->toBe('@everyone')
        ->and(data_get($meta, 'mentions.0.label'))->toBe('@everyone')
        ->and(data_get($meta, 'embeds.0.title'))->toBe('Release')
        ->and(data_get($meta, 'embeds.0.color'))->toBe('#5865F2');
});

it('persists the LinkedIn document_title meta on store', function () {
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Check our latest deck',
            'platforms' => [[
                'social_account_id' => $linkedin->id,
                'content_type' => ContentType::LinkedInPost->value,
                'meta' => ['document_title' => 'Q2 Report'],
            ]],
        ])
        ->assertCreated();

    expect(PostPlatform::where('social_account_id', $linkedin->id)->sole()->meta['document_title'])->toBe('Q2 Report');
});

it('publishes a LinkedIn document post that has a PDF', function () {
    Queue::fake();

    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Our latest deck',
        'media' => [[
            'id' => 'doc-1', 'path' => 'medias/deck.pdf', 'url' => 'https://example.com/deck.pdf',
            'type' => 'document', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf',
        ]],
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $linkedin->id,
        'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost, 'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [['id' => $platform->id, 'content_type' => ContentType::LinkedInPost->value]],
        ])
        ->assertOk();

    Queue::assertPushed(PublishPost::class);
});

it('rejects publishing a LinkedIn post that mixes a PDF with an image', function () {
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [
            ['id' => 'doc-1', 'path' => 'medias/deck.pdf', 'url' => 'https://example.com/deck.pdf', 'type' => 'document', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf'],
            ['id' => 'img-1', 'path' => 'medias/slide.jpg', 'url' => 'https://example.com/slide.jpg', 'type' => 'image', 'mime_type' => 'image/jpeg', 'original_filename' => 'slide.jpg'],
        ],
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $linkedin->id,
        'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost, 'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [['id' => $platform->id, 'content_type' => ContentType::LinkedInPost->value]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.content_type']);
});

it('publishes a valid LinkedIn document without resubmitting content_type', function () {
    Queue::fake();

    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Our deck',
        'media' => [[
            'id' => 'doc-1', 'path' => 'medias/deck.pdf', 'url' => 'https://example.com/deck.pdf',
            'type' => 'document', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf',
        ]],
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $linkedin->id,
        'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost, 'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [['id' => $platform->id]],
        ])
        ->assertOk();

    Queue::assertPushed(PublishPost::class);
});

it('rejects only the platform that cannot take a PDF in a multi-platform post', function () {
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $x = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::X]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Deck',
        'media' => [[
            'id' => 'doc-1', 'path' => 'medias/deck.pdf', 'url' => 'https://example.com/deck.pdf',
            'type' => 'document', 'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf',
        ]],
    ]);
    $linkedinPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $linkedin->id,
        'platform' => Platform::LinkedIn, 'content_type' => ContentType::LinkedInPost, 'enabled' => true,
    ]);
    $xPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $x->id,
        'platform' => Platform::X, 'content_type' => ContentType::XPost, 'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [
                ['id' => $linkedinPlatform->id, 'content_type' => ContentType::LinkedInPost->value],
                ['id' => $xPlatform->id, 'content_type' => ContentType::XPost->value],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.1.content_type'])
        ->assertJsonMissingValidationErrors(['platforms.0.content_type']);
});

it('persists per-platform meta across networks on store', function () {
    $instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);
    $tiktok = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::TikTok]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Cross-platform',
            'platforms' => [
                ['social_account_id' => $instagram->id, 'content_type' => ContentType::InstagramFeed->value, 'meta' => ['aspect_ratio' => '4:5']],
                ['social_account_id' => $pinterest->id, 'content_type' => ContentType::PinterestPin->value, 'meta' => ['board_id' => 'board-99']],
                ['social_account_id' => $tiktok->id, 'content_type' => ContentType::TikTokVideo->value, 'meta' => ['privacy_level' => 'SELF_ONLY', 'allow_comments' => true]],
            ],
        ])
        ->assertCreated();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta['aspect_ratio'])->toBe('4:5')
        ->and(PostPlatform::where('social_account_id', $pinterest->id)->sole()->meta['board_id'])->toBe('board-99')
        ->and(PostPlatform::where('social_account_id', $tiktok->id)->sole()->meta['privacy_level'])->toBe('SELF_ONLY')
        ->and(PostPlatform::where('social_account_id', $tiktok->id)->sole()->meta['allow_comments'])->toBeTrue();
});

it('allows saving a Discord draft without a channel', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->discord()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->discordAccount->id,
        'enabled' => true,
        'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [['id' => $platform->id]],
        ])
        ->assertOk();
});

it('rejects publishing without TikTok privacy and Pinterest board', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);
    $tiktok = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::TikTok]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $pinterestPlatform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $post->id, 'social_account_id' => $pinterest->id, 'enabled' => true, 'meta' => [],
    ]);
    $tiktokPlatform = PostPlatform::factory()->tiktok()->create([
        'post_id' => $post->id, 'social_account_id' => $tiktok->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [
                ['id' => $pinterestPlatform->id],
                ['id' => $tiktokPlatform->id],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'platforms.0.meta.board_id',
            'platforms.1.meta.privacy_level',
        ]);
});

it('rejects publishing a Discord post without a channel', function () {
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->discord()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->discordAccount->id,
        'enabled' => true,
        'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [['id' => $platform->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.channel_id']);
});

it('publishes a Discord post when the channel is set', function () {
    Queue::fake();

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->discord()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->discordAccount->id,
        'enabled' => true,
        'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [['id' => $platform->id, 'meta' => ['channel_id' => '444555666']]],
        ])
        ->assertOk();

    expect($platform->fresh()->meta['channel_id'])->toBe('444555666');
    Queue::assertPushed(PublishPost::class);
});

it('persists Pinterest title and link meta on store', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
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
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $pinterest->id)->sole()->meta;

    expect(data_get($meta, 'board_id'))->toBe('board-1')
        ->and(data_get($meta, 'title'))->toBe('Pin Title')
        ->and(data_get($meta, 'link'))->toBe('https://example.com/product')
        ->and(array_key_exists('description', $meta))->toBeFalse();
});

it('updates Pinterest title and link meta', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Shared caption',
    ]);
    $platform = PostPlatform::factory()->pinterest()->create([
        'post_id' => $post->id,
        'social_account_id' => $pinterest->id,
        'enabled' => true,
        'meta' => ['board_id' => 'board-1'],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => [
                    'board_id' => 'board-1',
                    'title' => 'Updated Title',
                    'link' => 'https://example.com/updated',
                ],
            ]],
        ])
        ->assertOk();

    $meta = $platform->fresh()->meta;

    expect(data_get($meta, 'title'))->toBe('Updated Title')
        ->and(data_get($meta, 'link'))->toBe('https://example.com/updated')
        ->and(data_get($meta, 'board_id'))->toBe('board-1');
});

it('rejects invalid Pinterest title and link on store', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Hello',
            'platforms' => [[
                'social_account_id' => $pinterest->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => [
                    'title' => str_repeat('t', 101),
                    'link' => 'not-a-url',
                ],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'platforms.0.meta.title' => __('posts.form.pinterest.title_max'),
            'platforms.0.meta.link' => __('posts.form.pinterest.link_invalid'),
        ]);
});

it('rejects non-http Pinterest links', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Hello',
            'platforms' => [[
                'social_account_id' => $pinterest->id,
                'content_type' => ContentType::PinterestPin->value,
                'meta' => ['link' => 'ftp://files.example.com/pin'],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'platforms.0.meta.link' => __('posts.form.pinterest.link_invalid'),
        ]);
});
