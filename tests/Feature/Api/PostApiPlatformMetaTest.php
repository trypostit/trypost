<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\GoogleBusiness\TopicType;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\PublishPost;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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

it('does not reject a Bluesky post whose stored video is a MOV', function () {
    Queue::fake();

    $bluesky = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'vid-1', 'path' => 'medias/clip.mov', 'url' => 'https://example.com/clip.mov',
            'type' => 'video', 'mime_type' => 'video/quicktime', 'original_filename' => 'clip.mov',
        ]],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $bluesky->id,
        'platform' => Platform::Bluesky, 'content_type' => ContentType::BlueskyPost, 'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Scheduled->value,
            'scheduled_at' => now()->addHour()->toIso8601String(),
        ])
        ->assertSuccessful();
});

it('rejects unowned media on the legacy single-platform update payload', function () {
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $target = PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $linkedin->id,
        'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'media' => [[
                'id' => 'foreign-asset',
                'path' => 'assets/foreign.jpg',
                'url' => 'https://example.com/foreign.jpg',
                'type' => 'image',
            ]],
            'platforms' => [['id' => $target->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['destinations.0.media.0.id']);

    expect($post->fresh()->media)->toBeEmpty();
});

it('rejects publishing when the uploaded video runs past the content type duration cap', function () {
    Storage::fake();
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram, 'content_type' => ContentType::InstagramStory, 'enabled' => true,
    ]);

    // Minimal MP4: ftyp + moov[mvhd v0, timescale 1, duration 90 s]. The server reads the duration itself.
    $mvhd = pack('N', 108).'mvhd'."\0\0\0\0".pack('N', 0).pack('N', 0).pack('N', 1).pack('N', 90).str_repeat("\0", 80);
    $file = UploadedFile::fake()->createWithContent('story.mp4', pack('N', 16).'ftypisom'.pack('N', 0).pack('N', 8 + strlen($mvhd)).'moov'.$mvhd);
    $file->mimeTypeToReport = 'video/mp4';

    $this->withHeaders($this->headers + ['Accept' => 'application/json'])
        ->post(route('api.posts.store-media', $post), ['media' => $file])
        ->assertOk();

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), ['status' => PostStatus::Publishing->value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.content_type'])
        ->assertJsonFragment(['Video is 1min 30s long, but this post type allows up to 1min.']);
});

it('rejects publishing a LinkedIn post with a GIF', function () {
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'gif-1', 'path' => 'medias/loop.gif', 'url' => 'https://example.com/loop.gif',
            'type' => 'image', 'mime_type' => 'image/gif', 'original_filename' => 'loop.gif',
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
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.content_type'])
        ->assertJsonFragment(['This platform does not accept GIF. Remove the GIF or choose a different network.']);
});

it('rejects publishing an Instagram Reel whose stored video exceeds 300 MB', function () {
    $instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'vid-1', 'path' => 'medias/reel.mp4', 'url' => 'https://example.com/reel.mp4',
            'type' => 'video', 'mime_type' => 'video/mp4', 'original_filename' => 'reel.mp4',
            'size' => 900 * 1024 * 1024,
        ]],
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram, 'content_type' => ContentType::InstagramReel, 'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), ['status' => PostStatus::Publishing->value])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'platforms.0.content_type' => trans('posts.form.warnings.video_too_large', ['max' => '300 MB', 'current' => '900.0 MB']),
        ]);

    expect($post->fresh()->status)->not->toBe(PostStatus::Publishing);
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
        ->postJson(route('api.posts.batch.store'), [
            'status' => 'draft',
            'content' => 'Cross-platform',
            'destinations' => [
                ['social_account_id' => $instagram->id, 'content_type' => ContentType::InstagramFeed->value, 'meta' => ['aspect_ratio' => '4:5']],
                ['social_account_id' => $pinterest->id, 'content_type' => ContentType::PinterestPin->value, 'meta' => ['board_id' => 'board-99']],
                ['social_account_id' => $tiktok->id, 'content_type' => ContentType::TikTokVideo->value, 'meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value, 'allow_comments' => true]],
            ],
        ])
        ->assertCreated();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta['aspect_ratio'])->toBe('4:5')
        ->and(PostPlatform::where('social_account_id', $pinterest->id)->sole()->meta['board_id'])->toBe('board-99')
        ->and(PostPlatform::where('social_account_id', $tiktok->id)->sole()->meta['privacy_level'])->toBe(PrivacyLevel::SelfOnly->value)
        ->and(PostPlatform::where('social_account_id', $tiktok->id)->sole()->meta['allow_comments'])->toBeTrue();
});

it('rejects an unknown TikTok privacy level on store', function () {
    $tiktok = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::TikTok]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Unknown privacy',
            'platforms' => [[
                'social_account_id' => $tiktok->id,
                'content_type' => ContentType::TikTokVideo->value,
                'meta' => ['privacy_level' => 'EVERYONE'],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.privacy_level']);
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

it('rejects publishing TikTok as self only branded content', function () {
    $tiktok = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::TikTok]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $tiktokPlatform = PostPlatform::factory()->tiktok()->create([
        'post_id' => $post->id, 'social_account_id' => $tiktok->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [[
                'id' => $tiktokPlatform->id,
                'meta' => [
                    'privacy_level' => PrivacyLevel::SelfOnly->value,
                    'brand_content_toggle' => true,
                ],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.privacy_level']);
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

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Ready for Discord',
    ]);
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

it('rejects scheduling an independent Pinterest draft without a board when settings are omitted', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);
    $image = Media::factory()->assets()->for($this->workspace, 'mediable')->create();
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'A pin',
        'media' => [MediaItem::fromMedia($image)->toArray()],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $pinterest->id,
        'platform' => Platform::Pinterest,
        'content_type' => ContentType::PinterestPin,
        'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Scheduled->value,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('destinations.0.meta.board_id');

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

it('accepts changing an independent Instagram reel to a feed image during scheduling', function () {
    Storage::fake(null, ['url' => 'https://cdn.example.com']);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $image = Media::factory()->assets()->for($this->workspace, 'mediable')->create();
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Instagram image',
        'media' => [MediaItem::fromMedia($image)->toArray()],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramReel,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Scheduled->value,
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'content_type' => ContentType::InstagramFeed->value,
        ])
        ->assertOk();

    expect($post->fresh()->status)->toBe(PostStatus::Scheduled)
        ->and($post->postPlatforms()->sole()->content_type)->toBe(ContentType::InstagramFeed);
});

it('persists Google Business topic_type and offer meta on store', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Big sale this week',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'topic_type' => 'OFFER',
                    'offer' => ['coupon_code' => 'SAVE10'],
                ],
            ]],
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $googleBusiness->id)->sole()->meta;

    expect(data_get($meta, 'topic_type'))->toBe('OFFER')
        ->and(data_get($meta, 'offer.coupon_code'))->toBe('SAVE10');
});

it('persists Google Business call_to_action meta on store', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Book a table tonight',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'call_to_action' => ['action_type' => 'BOOK', 'url' => 'https://example.com'],
                ],
            ]],
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $googleBusiness->id)->sole()->meta;

    expect(data_get($meta, 'call_to_action.action_type'))->toBe('BOOK')
        ->and(data_get($meta, 'call_to_action.url'))->toBe('https://example.com');
});

it('persists Google Business event time meta on store', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
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
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $googleBusiness->id)->sole()->meta;

    expect(data_get($meta, 'event.title'))->toBe('Grand Opening')
        ->and(data_get($meta, 'event.start_date'))->toBe('2026-09-01')
        ->and(data_get($meta, 'event.end_date'))->toBe('2026-09-02')
        ->and(data_get($meta, 'event.start_time'))->toBe('09:00')
        ->and(data_get($meta, 'event.end_time'))->toBe('17:00');
});

it('persists Google Business offer redeem url and terms meta on store', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
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
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $googleBusiness->id)->sole()->meta;

    expect(data_get($meta, 'offer.coupon_code'))->toBe('SAVE10')
        ->and(data_get($meta, 'offer.redeem_online_url'))->toBe('https://example.com/redeem')
        ->and(data_get($meta, 'offer.terms_conditions'))->toBe('Some terms');
});

it('rejects a Google Business event title over the api cap on update', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id, 'social_account_id' => $googleBusiness->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'topic_type' => 'EVENT',
                    'event' => [
                        'title' => str_repeat('t', TopicType::TITLE_MAX_LENGTH + 1),
                        'start_date' => '2026-09-01',
                        'end_date' => '2026-09-02',
                    ],
                ],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'platforms.0.meta.event.title' => __('posts.form.google_business.title_max'),
        ]);
});

it('rejects a Google Business event whose end date is before the start on update', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id, 'social_account_id' => $googleBusiness->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'topic_type' => 'EVENT',
                    'event' => ['title' => 'Sale', 'start_date' => '2026-09-10', 'end_date' => '2026-09-01'],
                ],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'platforms.0.meta.event.end_date' => __('posts.form.google_business.event_end_date_before_start'),
        ]);
});

it('rejects publishing a Google Business post with a GIF', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'media' => [[
            'id' => 'gif-1', 'path' => 'medias/loop.gif', 'url' => 'https://example.com/loop.gif',
            'type' => 'image', 'mime_type' => 'image/gif', 'original_filename' => 'loop.gif',
        ]],
    ]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id, 'social_account_id' => $googleBusiness->id, 'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [['id' => $platform->id, 'content_type' => ContentType::GoogleBusinessPost->value]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.content_type'])
        ->assertJsonFragment(['This platform does not accept GIF. Remove the GIF or choose a different network.']);
});

it('rejects a Google Business event title over the api cap on store', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Grand opening',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'topic_type' => 'EVENT',
                    'event' => [
                        'title' => str_repeat('t', TopicType::TITLE_MAX_LENGTH + 1),
                        'start_date' => '2026-09-01',
                        'end_date' => '2026-09-02',
                    ],
                ],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'platforms.0.meta.event.title' => __('posts.form.google_business.title_max'),
        ]);
});

it('rejects publishing a Google Business offer post without a title', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id, 'social_account_id' => $googleBusiness->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => ['topic_type' => 'OFFER', 'event' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-02']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([
            'platforms.0.meta.event.title' => __('posts.form.google_business.offer_title_required'),
        ]);
});

it('rejects publishing a Google Business event post without event fields', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id, 'social_account_id' => $googleBusiness->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [['id' => $platform->id, 'meta' => ['topic_type' => 'EVENT']]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.event.title']);
});

it('rejects publishing a Google Business offer post without dates', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id, 'social_account_id' => $googleBusiness->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => ['topic_type' => 'OFFER', 'event' => ['title' => 'Summer Sale']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.event.start_date']);
});

it('rejects publishing a Google Business event with a same-day end time before start', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id, 'social_account_id' => $googleBusiness->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => [
                    'topic_type' => 'EVENT',
                    'event' => [
                        'title' => 'Sale',
                        'start_date' => '2026-09-01',
                        'end_date' => '2026-09-01',
                        'start_time' => '18:00',
                        'end_time' => '09:00',
                    ],
                ],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.event.end_time']);
});

it('rejects a Google Business GET_OFFER call to action', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Sale',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'topic_type' => 'STANDARD',
                    'call_to_action' => ['action_type' => 'GET_OFFER'],
                ],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.call_to_action.action_type']);
});

it('rejects publishing a Google Business post with a url-needing cta and no url', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id, 'social_account_id' => $googleBusiness->id, 'enabled' => true, 'meta' => [],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Publishing->value,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => ['topic_type' => 'STANDARD', 'call_to_action' => ['action_type' => 'BOOK']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.call_to_action.url']);
});
