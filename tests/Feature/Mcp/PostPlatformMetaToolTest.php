<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\GoogleBusiness\TopicType;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\PublishPost;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;

test('youtube description persists reads retains and clears in MCP', function (string $description) {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Short title',
        'platforms' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::YouTubeShort->value,
            'meta' => ['description' => $description],
        ]],
    ])->assertOk();
    $platform = PostPlatform::where('social_account_id', $account->id)->sole();
    TryPostServer::actingAs($this->user)->tool(GetPostTool::class, ['post_id' => $platform->post_id])
        ->assertOk()->assertStructuredContent(function (AssertableJson $json) use ($platform, $description) {
            $json->etc();
            expect(collect($json->toArray()['platforms'])->firstWhere('id', $platform->id)['meta']['description'])->toBe($description);
        });
    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $platform->post_id,
        'meta' => ['description' => str_repeat('é', 2501)],
    ])->assertHasErrors([__('posts.form.youtube.description_max')]);
    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $platform->post_id,
        'meta' => [],
    ])->assertOk();
    expect(data_get($platform->fresh()->meta, 'description'))->toBe($description);
    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $platform->post_id,
        'meta' => ['description' => null],
    ])->assertOk();
    expect(data_get($platform->fresh()->meta, 'description'))->toBeNull();
})->with([
    'multiline description' => ["Full text\nhttps://example.com"],
    'programming text' => ["if (a < b && c > d) {}\n<p>Text about HTML</p>"],
]);

test('youtube description rejects invalid MCP create input', function (string $description) {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Short title',
        'platforms' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::YouTubeShort->value,
            'meta' => ['description' => $description],
        ]],
    ])->assertHasErrors();
})->with([
    'multibyte overflow' => [str_repeat('é', 2501)],
    'ascii overflow' => [str_repeat('a', 5001)],
    'emoji overflow' => [str_repeat('😀', 1251)],
]);

test('youtube description checks effective MCP metadata on schedule and publish', function (string $patch, bool $allowed) {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Short title',
        'status' => PostStatus::Draft,
        'media' => [[
            'id' => 'video-1',
            'type' => 'video',
            'path' => 'medias/video.mp4',
            'url' => 'https://example.com/video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'video.mp4',
        ]],
    ]);
    $platform = PostPlatform::factory()->youtube()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'enabled' => true,
        'meta' => ['description' => str_repeat('a', 5001)],
    ]);
    Queue::fake();
    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, ['post_id' => $post->id])->assertHasErrors([__('posts.form.youtube.description_max')]);
    Queue::assertNotPushed(PublishPost::class);
    $data = [
        'post_id' => $post->id,
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ];

    if ($patch !== 'omit') {
        $data['meta'] = $patch === 'row' ? [] : ['description' => $patch === 'clear' ? null : 'Valid description'];
    }
    $response = TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, $data);

    if ($allowed) {
        $response->assertOk();
        expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
    } else {
        $response->assertHasErrors([__('posts.form.youtube.description_max')]);
        expect($post->fresh()->status)->toBe(PostStatus::Draft);
    }
})->with([
    'stored invalid description' => ['omit', false],
    'retained invalid description' => ['row', false],
    'replaced description' => ['replace', true],
    'cleared description' => ['clear', true],
]);

beforeEach(function () {
    Storage::fake();
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
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
            'meta' => ['channel_id' => '444555666'],
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
        'content' => 'Ready to publish',
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

test('create post rejects an unknown TikTok privacy level', function () {
    $tiktok = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::TikTok]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Unknown privacy',
            'platforms' => [[
                'social_account_id' => $tiktok->id,
                'content_type' => ContentType::TikTokVideo->value,
                'meta' => ['privacy_level' => 'EVERYONE'],
            ]],
        ]);

    $response->assertHasErrors();
});

test('publish post rejects stored TikTok self only branded content', function () {
    $tiktok = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::TikTok]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->tiktok()->create([
        'post_id' => $post->id,
        'social_account_id' => $tiktok->id,
        'enabled' => true,
        'meta' => [
            'privacy_level' => PrivacyLevel::SelfOnly->value,
            'brand_content_toggle' => true,
        ],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.tiktok.privacy.private_disabled_branded')]);
});

test('publish post rejects a stored unknown TikTok privacy level', function () {
    $tiktok = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::TikTok]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->tiktok()->create([
        'post_id' => $post->id,
        'social_account_id' => $tiktok->id,
        'enabled' => true,
        'meta' => ['privacy_level' => 'EVERYONE'],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.tiktok.privacy_required')]);
});

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
        'collection' => 'uploads',
        'type' => 'document',
        'path' => 'medias/deck.pdf',
        'original_filename' => 'deck.pdf',
        'mime_type' => 'application/pdf',
        'size' => 1000,
        'order' => 0,
        'upload_token' => $uploadToken,
    ]);
    Storage::put('medias/deck.pdf', 'pdf bytes');

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

    $response->assertHasErrors(['A PDF must be posted on its own, without other images or videos.']);
});

test('publish post accepts a Bluesky post whose stored video is a MOV', function () {
    Queue::fake();

    $bluesky = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'media' => [
            ['id' => 'vid-1', 'path' => 'medias/clip.mov', 'url' => 'https://example.com/clip.mov', 'type' => 'video', 'mime_type' => 'video/quicktime', 'original_filename' => 'clip.mov'],
        ],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $bluesky->id,
        'platform' => Platform::Bluesky, 'content_type' => ContentType::BlueskyPost, 'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, [
            'post_id' => $post->id,
            'scheduled_at' => '2037-12-31T15:30:00Z',
        ]);

    $response->assertOk();
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);
});

test('publish post rejects an Instagram Reel whose stored video exceeds 300 MB', function () {
    $instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'media' => [
            ['id' => 'vid-1', 'path' => 'medias/reel.mp4', 'url' => 'https://example.com/reel.mp4', 'type' => 'video', 'mime_type' => 'video/mp4', 'original_filename' => 'reel.mp4', 'size' => 900 * 1024 * 1024],
        ],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram, 'content_type' => ContentType::InstagramReel, 'enabled' => true,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([trans('posts.form.warnings.video_too_large', ['max' => '300 MB', 'current' => '900.0 MB'])]);
    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('publish post succeeds for a Discord platform with a channel', function () {
    Queue::fake();

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
        'content' => 'Ready for Discord',
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
            'meta' => [
                'board_id' => 'board-1',
                'title' => 'Updated Title',
                'link' => 'https://example.com/updated',
            ],
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

test('updating an independent Pinterest draft cannot schedule without its stored board', function () {
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Pinterest]);
    $image = Media::factory()->stored()->temporaryUpload($this->workspace)->create();
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

    TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'status' => PostStatus::Scheduled->value,
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ])
        ->assertHasErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
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

test('create post rejects a Google Business event title over the api cap', function () {
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
                        'title' => str_repeat('t', TopicType::TITLE_MAX_LENGTH + 1),
                        'start_date' => '2026-09-01',
                        'end_date' => '2026-09-02',
                    ],
                ],
            ]],
        ]);

    $response->assertHasErrors([__('posts.form.google_business.title_max')]);
});

test('update post rejects a Google Business event title over the api cap', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id,
        'social_account_id' => $googleBusiness->id,
        'enabled' => true,
        'meta' => [],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'meta' => [
                'topic_type' => 'EVENT',
                'event' => [
                    'title' => str_repeat('t', TopicType::TITLE_MAX_LENGTH + 1),
                    'start_date' => '2026-09-01',
                    'end_date' => '2026-09-02',
                ],
            ],
        ]);

    $response->assertHasErrors([__('posts.form.google_business.title_max')]);
});

test('update post rejects a Google Business event whose end date is before the start', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->googleBusiness()->create([
        'post_id' => $post->id,
        'social_account_id' => $googleBusiness->id,
        'enabled' => true,
        'meta' => [],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdatePostTool::class, [
            'post_id' => $post->id,
            'meta' => [
                'topic_type' => 'EVENT',
                'event' => ['title' => 'Sale', 'start_date' => '2026-09-10', 'end_date' => '2026-09-01'],
            ],
        ]);

    $response->assertHasErrors([__('posts.form.google_business.event_end_date_before_start')]);
});

test('publish post rejects a Google Business event title over the api cap', function () {
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
        'meta' => [
            'topic_type' => 'EVENT',
            'event' => [
                'title' => str_repeat('t', TopicType::TITLE_MAX_LENGTH + 1),
                'start_date' => '2026-09-01',
                'end_date' => '2026-09-02',
            ],
        ],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.google_business.title_max')]);
});

test('publish post rejects a Google Business offer post without a title', function () {
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
        'meta' => [
            'topic_type' => 'OFFER',
            'event' => ['start_date' => '2026-09-01', 'end_date' => '2026-09-02'],
        ],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.google_business.offer_title_required')]);
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

test('publish post rejects a Google Business offer post without dates', function () {
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
        'meta' => [
            'topic_type' => 'OFFER',
            'event' => ['title' => 'Summer Sale'],
        ],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.google_business.event_start_date_required')]);
});

test('publish post rejects a Google Business event with a same-day end time before start', function () {
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
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.google_business.event_end_time_before_start')]);
});

test('create post rejects a Google Business GET_OFFER call to action', function () {
    $googleBusiness = SocialAccount::factory()->googleBusiness()->create(['workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Sale',
            'platforms' => [[
                'social_account_id' => $googleBusiness->id,
                'content_type' => ContentType::GoogleBusinessPost->value,
                'meta' => [
                    'topic_type' => 'STANDARD',
                    'call_to_action' => ['action_type' => 'GET_OFFER'],
                ],
            ]],
        ]);

    $response->assertHasErrors();
});

test('publish post rejects a Google Business post with a url-needing cta and no url', function () {
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
        'meta' => [
            'topic_type' => 'STANDARD',
            'call_to_action' => ['action_type' => 'BOOK'],
        ],
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(PublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors([__('posts.form.google_business.cta_url_required')]);
});

test('create post persists youtube metadata in MCP', function () {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Short title',
        'platforms' => [[
            'social_account_id' => $account->id,
            'content_type' => ContentType::YouTubeShort->value,
            'meta' => ['title' => 'Title', 'privacy_status' => 'unlisted', 'made_for_kids' => true, 'is_ai_generated' => true],
        ]],
    ])->assertOk();

    expect(PostPlatform::where('social_account_id', $account->id)->sole()->meta)
        ->toEqual(['title' => 'Title', 'privacy_status' => 'unlisted', 'made_for_kids' => true, 'is_ai_generated' => true]);
});

test('create post rejects an unknown youtube privacy status in MCP', function () {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Short title',
        'platforms' => [['social_account_id' => $account->id, 'content_type' => ContentType::YouTubeShort->value, 'meta' => ['privacy_status' => 'friends']]],
    ])->assertHasErrors();
});

test('create post persists instagram options in MCP', function () {
    $instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Reel',
        'platforms' => [['social_account_id' => $instagram->id, 'content_type' => ContentType::InstagramReel->value, 'meta' => ['share_to_feed' => false]]],
    ])->assertOk();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta)->toEqual(['share_to_feed' => false]);
});

test('create post rejects a youtube title with angle brackets on a draft in MCP', function () {
    $account = SocialAccount::factory()->youtube()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Short title',
        'platforms' => [['social_account_id' => $account->id, 'content_type' => ContentType::YouTubeShort->value, 'meta' => ['title' => '<b>']]],
    ])->assertHasErrors();

    expect(PostPlatform::where('social_account_id', $account->id)->exists())->toBeFalse();
});

test('create post persists a dropped link preview', function () {
    $bluesky = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky]);

    TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Read https://example.com/article',
            'platforms' => [[
                'social_account_id' => $bluesky->id,
                'content_type' => ContentType::BlueskyPost->value,
                'meta' => ['link_preview' => false],
            ]],
        ])
        ->assertOk();

    expect(PostPlatform::where('social_account_id', $bluesky->id)->sole()->meta)->toEqual(['link_preview' => false]);
});

test('create post persists a threads topic tag and rejects one with an ampersand in MCP', function () {
    $threads = SocialAccount::factory()->threads()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Hi',
        'platforms' => [['social_account_id' => $threads->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => ['topic_tag' => 'laravel']]],
    ])->assertOk();

    expect(PostPlatform::where('social_account_id', $threads->id)->sole()->meta)->toEqual(['topic_tag' => 'laravel']);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Hi',
        'platforms' => [['social_account_id' => $threads->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => ['topic_tag' => 'rock&roll']]],
    ])->assertHasErrors();

    expect(PostPlatform::where('social_account_id', $threads->id)->count())->toBe(1);
});

test('scheduling an instagram post with more than five hashtags is rejected in MCP', function () {
    $instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Launch #a #b #c #d #e #f',
        'status' => PostStatus::Draft,
        'media' => [['id' => 'image-1', 'type' => 'image', 'path' => 'medias/image.jpg', 'url' => 'https://example.com/image.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'image.jpg']],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $instagram->id,
        'platform' => Platform::Instagram, 'content_type' => ContentType::InstagramFeed, 'enabled' => true,
    ]);

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $post->id,
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => now()->addHour()->toIso8601String(),
    ])->assertHasErrors([__('posts.form.hashtags_exceed_platform', ['platform' => Platform::Instagram->label(), 'limit' => 5])]);

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('a stored mastodon content warning counts toward the limit when scheduling through MCP', function () {
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);
    $limit = Platform::Mastodon->maxContentLength();

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => str_repeat('b', $limit - 9),
        'platforms' => [['social_account_id' => $mastodon->id, 'content_type' => ContentType::MastodonPost->value, 'meta' => ['spoiler_text' => str_repeat('a', 10)]]],
    ])->assertOk();
    $platform = PostPlatform::where('social_account_id', $mastodon->id)->sole();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $platform->post_id,
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => '2037-12-31T15:30:00Z',
    ])->assertHasErrors();

    expect($platform->post->fresh()->status)->toBe(PostStatus::Draft);
});

test('create post rejects a numeric link preview in MCP', function (mixed $value) {
    $bluesky = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Bluesky]);

    TryPostServer::actingAs($this->user)
        ->tool(CreatePostTool::class, [
            'content' => 'Read https://example.com/article',
            'platforms' => [[
                'social_account_id' => $bluesky->id,
                'content_type' => ContentType::BlueskyPost->value,
                'meta' => ['link_preview' => $value],
            ]],
        ])
        ->assertHasErrors();

    expect(PostPlatform::where('social_account_id', $bluesky->id)->exists())->toBeFalse();
})->with([0, '0']);

test('publish post rejects a stored threads ghost post whose text carries a link in MCP', function () {
    $threads = SocialAccount::factory()->threads()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'content' => 'Read https://example.com/article', 'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->threads()->create(['post_id' => $post->id, 'social_account_id' => $threads->id, 'enabled' => true, 'content_type' => ContentType::ThreadsGhostPost]);
    Queue::fake();

    TryPostServer::actingAs($this->user)->tool(PublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors([__('posts.form.warnings.text_only')]);
    Queue::assertNotPushed(PublishPost::class);
});

test('create post judges a threads topic tag without its leading hash in MCP', function () {
    $threads = SocialAccount::factory()->threads()->create(['workspace_id' => $this->workspace->id]);
    $tag = str_repeat('a', 50);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Hi',
        'platforms' => [['social_account_id' => $threads->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => ['topic_tag' => "#{$tag}"]]],
    ])->assertOk();

    expect(PostPlatform::where('social_account_id', $threads->id)->sole()->meta)->toEqual(['topic_tag' => $tag]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Hi',
        'platforms' => [['social_account_id' => $threads->id, 'content_type' => ContentType::ThreadsPost->value, 'meta' => ['topic_tag' => "#{$tag}a"]]],
    ])->assertHasErrors([__('posts.form.threads.topic_invalid')]);
});

test('create post persists thread replies in MCP', function () {
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Root',
        'platforms' => [['social_account_id' => $mastodon->id, 'content_type' => ContentType::MastodonPost->value, 'meta' => ['thread_replies' => ['Two']]]],
    ])->assertOk();

    expect(PostPlatform::where('social_account_id', $mastodon->id)->sole()->meta)->toEqual(['thread_replies' => ['Two']]);
});

test('a stored mastodon thread reply that does not fit blocks scheduling through MCP', function () {
    $mastodon = SocialAccount::factory()->mastodon()->create(['workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($this->user)->tool(CreatePostTool::class, [
        'content' => 'Root',
        'platforms' => [['social_account_id' => $mastodon->id, 'content_type' => ContentType::MastodonPost->value, 'meta' => ['spoiler_text' => 'Ten chars!', 'thread_replies' => [str_repeat('a', 495)]]]],
    ])->assertOk();
    $platform = PostPlatform::where('social_account_id', $mastodon->id)->sole();

    TryPostServer::actingAs($this->user)->tool(UpdatePostTool::class, [
        'post_id' => $platform->post_id,
        'status' => PostStatus::Scheduled->value,
        'scheduled_at' => '2037-12-31T15:30:00Z',
    ])->assertHasErrors();

    expect($platform->post->fresh()->status)->toBe(PostStatus::Draft);
});
