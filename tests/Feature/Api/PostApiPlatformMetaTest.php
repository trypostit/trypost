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

it('persists Instagram collaborators and strips at signs', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Collab reel',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramReel->value,
                'meta' => ['collaborators' => ['@Host_One', 'host_two']],
            ]],
        ])
        ->assertCreated();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta)
        ->toMatchArray([
            'collaborators' => ['Host_One', 'host_two'],
        ]);
});

it('rejects Instagram collaborators sent as a comma-separated string', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Collab reel',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramReel->value,
                'meta' => ['collaborators' => 'Host_One,host_two'],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.collaborators']);
});

it('persists Instagram Facebook collaborators', function () {
    $instagram = SocialAccount::factory()->instagramFacebook()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Collab reel',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramReel->value,
                'meta' => ['collaborators' => ['@Host_One']],
            ]],
        ])
        ->assertCreated();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta['collaborators'])
        ->toBe(['Host_One']);
});

it('rejects duplicate Instagram collaborators', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Dupes',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['Host_One', 'host_one']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.collaborators.1']);
});

it('rejects Instagram collaborator usernames that start with a period', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Bad username',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['.user']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.collaborators.0']);
});

it('clears Instagram collaborators when creating a story', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Story',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramStory->value,
                'meta' => ['collaborators' => ['@Host_One']],
            ]],
        ])
        ->assertCreated();

    expect(PostPlatform::where('social_account_id', $instagram->id)->sole()->meta['collaborators'])
        ->toBe([]);
});

it('persists Instagram collaborators on update', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'enabled' => true,
        'meta' => ['aspect_ratio' => '4:5'],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
                'meta' => ['collaborators' => ['@Host_One']],
            ]],
        ])
        ->assertOk();

    expect(data_get($platform->fresh()->meta, 'collaborators'))->toBe(['Host_One'])
        ->and(data_get($platform->fresh()->meta, 'aspect_ratio'))->toBe('4:5');
});

it('still validates Instagram collaborators when update also sends another social_account_id', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
                'social_account_id' => $tiktok->id,
                'meta' => ['collaborators' => ['a', 'b', 'c', 'd', 'not valid!!']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.collaborators']);
});

it('still validates Discord mentions when update also sends another social_account_id', function () {
    $tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->discord()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->discordAccount->id,
        'enabled' => true,
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
                'social_account_id' => $tiktok->id,
                'meta' => ['mentions' => ['@everyone']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.mentions.0.token']);
});

it('clears Instagram collaborators when the platform is switched to a story', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'enabled' => true,
        'meta' => ['collaborators' => ['Host_One']],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
                'content_type' => ContentType::InstagramStory->value,
                'meta' => ['collaborators' => ['host_two']],
            ]],
        ])
        ->assertOk();

    expect(data_get($platform->fresh()->meta, 'collaborators'))->toBe([]);
});

it('clears Instagram collaborators when switching to a story without sending meta', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'enabled' => true,
        'content_type' => ContentType::InstagramReel->value,
        'meta' => ['collaborators' => ['Host_One'], 'aspect_ratio' => '4:5'],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
                'content_type' => ContentType::InstagramStory->value,
            ]],
        ])
        ->assertOk();

    expect(data_get($platform->fresh()->meta, 'collaborators'))->toBe([])
        ->and(data_get($platform->fresh()->meta, 'aspect_ratio'))->toBe('4:5')
        ->and($platform->fresh()->content_type)->toBe(ContentType::InstagramStory);
});

it('clears leftover Instagram collaborators on an already-story row without sending content_type', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Draft,
    ]);
    $platform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => $instagram->id,
        'enabled' => true,
        'content_type' => ContentType::InstagramStory->value,
        'meta' => ['collaborators' => ['Host_One'], 'aspect_ratio' => '4:5'],
    ]);

    $this->withHeaders($this->headers)
        ->putJson(route('api.posts.update', $post), [
            'status' => PostStatus::Draft->value,
            'platforms' => [[
                'id' => $platform->id,
            ]],
        ])
        ->assertOk();

    expect(data_get($platform->fresh()->meta, 'collaborators'))->toBe([])
        ->and(data_get($platform->fresh()->meta, 'aspect_ratio'))->toBe('4:5')
        ->and($platform->fresh()->content_type)->toBe(ContentType::InstagramStory);
});

it('rejects more than three Instagram collaborators', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Too many',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['a', 'b', 'c', 'd']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.collaborators']);
});

it('rejects tagging the connected Instagram account as a collaborator', function () {
    $instagram = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'testuser',
    ]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Self collab',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['@TestUser']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.collaborators.0']);
});

it('still enforces collaborator rules when a create payload carries a stale platform id', function () {
    $instagram = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'testuser',
    ]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'A round-tripped GET response still carries id',
            'platforms' => [[
                'id' => '0198c3f1-1111-7abc-9def-000000000000',
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['not valid!!']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.collaborators.0']);
});

it('accepts a story without complaining about collaborators it is going to drop', function () {
    $instagram = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'testuser',
    ]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Stories cannot be co-authored',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramStory->value,
                'meta' => ['collaborators' => ['@TestUser', 'a', 'b', 'c', 'not valid!!']],
            ]],
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $instagram->id)->sole()->meta;

    expect(data_get($meta, 'collaborators'))->toBe([]);
});

it('does not apply Instagram collaborator rules to TikTok meta', function () {
    $tiktok = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'testuser',
    ]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'TikTok can reuse the key later',
            'platforms' => [[
                'social_account_id' => $tiktok->id,
                'content_type' => ContentType::TikTokVideo->value,
                'meta' => [
                    'privacy_level' => 'SELF_ONLY',
                    'collaborators' => ['@TestUser', 'a', 'b', 'c', 'not valid!!'],
                    'mentions' => [['token' => '@someone', 'label' => 'Someone']],
                ],
            ]],
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $tiktok->id)->sole()->meta;

    expect(data_get($meta, 'collaborators'))->toBe(['@TestUser', 'a', 'b', 'c', 'not valid!!'])
        ->and(data_get($meta, 'mentions'))->toBe([['token' => '@someone', 'label' => 'Someone']]);
});

it('strips unknown keys from mentions instead of persisting arbitrary json', function () {
    $discord = SocialAccount::factory()->discord()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Mentions are whitelisted',
            'platforms' => [[
                'social_account_id' => $discord->id,
                'content_type' => ContentType::DiscordMessage->value,
                'meta' => [
                    'channel_id' => '123',
                    'mentions' => [['token' => '<@1>', 'label' => 'One', 'payload' => ['deep' => 'junk']]],
                ],
            ]],
        ])
        ->assertCreated();

    $meta = PostPlatform::where('social_account_id', $discord->id)->sole()->meta;

    expect(data_get($meta, 'mentions'))->toBe([['token' => '<@1>', 'label' => 'One']]);
});

it('rejects an invalid Instagram collaborator username', function () {
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);

    $this->withHeaders($this->headers)
        ->postJson(route('api.posts.store'), [
            'content' => 'Bad username',
            'platforms' => [[
                'social_account_id' => $instagram->id,
                'content_type' => ContentType::InstagramFeed->value,
                'meta' => ['collaborators' => ['not valid!!']],
            ]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['platforms.0.meta.collaborators.0']);
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
