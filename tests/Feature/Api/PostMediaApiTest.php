<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

// A public IP literal as the host lets SafeHttpFetcher's SSRF guard pass without
// a real DNS lookup; Http::fake() intercepts the request before any network I/O.

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    PostPlatform::factory()->linkedin()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'enabled' => true,
    ]);

    Storage::fake();
});

it('attaches media from url', function () {
    Http::fake([
        'example.com/photo.png' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.attach-media-from-url', $this->post), [
            'urls' => [['url' => 'https://example.com/photo.png']],
        ])
        ->assertOk()
        ->assertJsonPath('attached_count', 1)
        ->assertJsonPath('failed_urls', []);

    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(1);
    expect($this->post->fresh()->media)->toHaveCount(1);
});

it('attaches media from url with alt text', function () {
    Http::fake([
        'example.com/photo.png' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.attach-media-from-url', $this->post), [
            'urls' => [['url' => 'https://example.com/photo.png', 'alt' => 'A red bicycle by a wall']],
        ])
        ->assertOk()
        ->assertJsonPath('attached_count', 1);

    expect(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBe('A red bicycle by a wall');
});

it('does not store alt text on a non-image url', function () {
    Http::fake([
        'example.com/deck.pdf' => Http::response(
            "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
            200,
            ['Content-Type' => 'application/pdf'],
        ),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.attach-media-from-url', $this->post), [
            'urls' => [['url' => 'https://example.com/deck.pdf', 'alt' => 'alt is meaningless for a pdf']],
        ])
        ->assertOk()
        ->assertJsonPath('attached_count', 1);

    expect(data_get($this->post->fresh()->media, '0.type'))->toBe('document')
        ->and(data_get($this->post->fresh()->media, '0.meta'))->toBeNull();
});

it('rejects alt text over the max length on a url', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.attach-media-from-url', $this->post), [
            'urls' => [['url' => 'https://example.com/photo.png', 'alt' => str_repeat('a', 2001)]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['urls.0.alt']);
});

it('rejects the old bare-string urls shape', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.attach-media-from-url', $this->post), [
            'urls' => ['https://example.com/photo.png'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['urls.0.url']);
});

it('reports failures for unreachable urls', function () {
    Http::fake([
        'example.com/missing.png' => Http::response(null, 404),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.attach-media-from-url', $this->post), [
            'urls' => [['url' => 'https://example.com/missing.png']],
        ])
        ->assertOk()
        ->assertJsonPath('attached_count', 0)
        ->assertJsonPath('failed_urls.0', 'https://example.com/missing.png');
});

it('cannot attach media to a post from another workspace', function () {
    $other = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $other->id, 'user_id' => $this->user->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.attach-media-from-url', $post), [
            'urls' => [['url' => 'https://example.com/photo.png']],
        ])
        ->assertNotFound();
});

it('previews per platform with sanitized content and length', function () {
    $this->post->update(['content' => str_repeat('a', 500)]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.preview', $this->post))
        ->assertOk()
        ->assertJsonStructure([
            'post_id',
            'original_content',
            'original_length',
            'platforms' => [
                '*' => [
                    'post_platform_id',
                    'platform',
                    'content_type',
                    'sanitized_content',
                    'sanitized_length',
                    'max_content_length',
                    'truncated',
                ],
            ],
        ])
        ->assertJsonPath('original_length', 500);
});

it('returns metrics shape including unsupported reason for unpublished platforms', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.metrics', $this->post))
        ->assertOk()
        ->assertJsonStructure([
            'post_id',
            'platforms' => [
                '*' => [
                    'post_platform_id',
                    'platform',
                    'status',
                    'platform_post_id',
                    'platform_url',
                    'metrics',
                ],
            ],
        ])
        ->assertJsonPath('platforms.0.metrics.unsupported', true)
        ->assertJsonPath('platforms.0.metrics.reason', 'not_published');
});

it('cannot get metrics from another workspace post', function () {
    $other = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $other->id, 'user_id' => $this->user->id]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.metrics', $post))
        ->assertNotFound();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.posts.preview', $post))
        ->assertNotFound();
});

it('rejects attach media payload without urls', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.attach-media-from-url', $this->post), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['urls']);
});

it('uploads a media file and attaches it to the post', function () {
    $file = UploadedFile::fake()->createWithContent(
        'photo.png',
        file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
    );

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken, 'Accept' => 'application/json'])
        ->post(route('api.posts.store-media', $this->post), ['media' => $file])
        ->assertOk();

    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(1);
    expect($this->post->fresh()->media)->toHaveCount(1);
});

it('rejects upload of an unsupported mime type', function () {
    $file = UploadedFile::fake()->createWithContent('doc.txt', 'plain text content');

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken, 'Accept' => 'application/json'])
        ->post(route('api.posts.store-media', $this->post), ['media' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);
});

it('rejects upload when the file type is not supported by enabled platforms', function () {
    $tiktokAccount = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $tiktokOnlyPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    PostPlatform::factory()->tiktok()->create([
        'post_id' => $tiktokOnlyPost->id,
        'social_account_id' => $tiktokAccount->id,
        'enabled' => true,
    ]);

    $file = UploadedFile::fake()->createWithContent(
        'photo.png',
        file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
    );

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken, 'Accept' => 'application/json'])
        ->post(route('api.posts.store-media', $tiktokOnlyPost), ['media' => $file])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);
});

it('cannot upload media to a post from another workspace', function () {
    $other = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $other->id, 'user_id' => $this->user->id]);

    $file = UploadedFile::fake()->createWithContent(
        'photo.png',
        file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
    );

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken, 'Accept' => 'application/json'])
        ->post(route('api.posts.store-media', $post), ['media' => $file])
        ->assertNotFound();
});

it('rejects upload without a media file', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store-media', $this->post), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);
});

it('downloads and hosts an external media url when creating a post', function () {
    $this->socialAccount->update(['is_active' => true]);

    Http::fake([
        '93.184.216.34/listing.jpg' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'External media post',
            'media' => [['url' => 'https://93.184.216.34/listing.jpg']],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertCreated();

    $media = Post::where('content', 'External media post')->firstOrFail()->media;

    expect($media)->toHaveCount(1)
        ->and(data_get($media, '0.path'))->not->toBeNull()
        ->and(data_get($media, '0.url'))->not->toContain('93.184.216.34');
    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(1);
});

it('persists alt text submitted on a bare external media url', function () {
    $this->socialAccount->update(['is_active' => true]);

    Http::fake([
        '93.184.216.34/car.jpg' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'External alt post',
            'media' => [[
                'url' => 'https://93.184.216.34/car.jpg',
                'meta' => ['alt_text' => 'A red car parked on a hill'],
            ]],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertCreated();

    $media = Post::where('content', 'External alt post')->firstOrFail()->media;

    expect(data_get($media, '0.meta.alt_text'))->toBe('A red car parked on a hill')
        ->and(data_get($media, '0.path'))->not->toBeNull();
});

it('rejects creating a post when an external media url cannot be fetched', function () {
    $this->socialAccount->update(['is_active' => true]);

    Http::fake(['93.184.216.34/missing.jpg' => Http::response(null, 404)]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'Broken media post',
            'media' => [['url' => 'https://93.184.216.34/missing.jpg']],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);

    expect(Post::where('content', 'Broken media post')->exists())->toBeFalse();
    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(0);
});

it('rolls back already-hosted media when another url in the batch fails', function () {
    $this->socialAccount->update(['is_active' => true]);

    Http::fake([
        '93.184.216.34/good.jpg' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
        '93.184.216.34/missing.jpg' => Http::response(null, 404),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'Partial media post',
            'media' => [
                ['url' => 'https://93.184.216.34/good.jpg'],
                ['url' => 'https://93.184.216.34/missing.jpg'],
            ],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);

    expect(Post::where('content', 'Partial media post')->exists())->toBeFalse();
    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(0);
});

it('rejects and rolls back when a media url connection fails (timeout/dns)', function () {
    $this->socialAccount->update(['is_active' => true]);

    Http::fake([
        '93.184.216.34/good.jpg' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
        '93.184.216.34/timeout.jpg' => fn () => throw new ConnectionException('Connection timed out'),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'Timeout media post',
            'media' => [
                ['url' => 'https://93.184.216.34/good.jpg'],
                ['url' => 'https://93.184.216.34/timeout.jpg'],
            ],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);

    expect(Post::where('content', 'Timeout media post')->exists())->toBeFalse();
    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(0);
});

it('rejects creating a post when an external media url is not a supported type', function () {
    $this->socialAccount->update(['is_active' => true]);

    // Downloads fine (200) but the bytes are not a supported media type.
    Http::fake(['93.184.216.34/notes.txt' => Http::response('just some text', 200)]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'Bad type post',
            'media' => [['url' => 'https://93.184.216.34/notes.txt']],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);

    expect(Post::where('content', 'Bad type post')->exists())->toBeFalse();
    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(0);
});

it('keeps an already-hosted item and a freshly-hosted url in order', function () {
    $this->socialAccount->update(['is_active' => true]);

    Http::fake([
        '93.184.216.34/external.jpg' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'Mixed media post',
            'media' => [
                ['id' => 'hosted-1', 'path' => 'assets/already.jpg', 'url' => 'https://cdn.trypost.test/assets/already.jpg', 'type' => 'image'],
                ['url' => 'https://93.184.216.34/external.jpg'],
            ],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertCreated();

    $media = Post::where('content', 'Mixed media post')->firstOrFail()->media;

    expect($media)->toHaveCount(2)
        ->and(data_get($media, '0.path'))->toBe('assets/already.jpg')
        ->and(data_get($media, '1.url'))->not->toContain('93.184.216.34')
        ->and(data_get($media, '1.path'))->not->toBeNull();
    // Only the external URL is hosted; the passed-through item creates no new row.
    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(1);
});

it('passes already-hosted media through on create without downloading', function () {
    $this->socialAccount->update(['is_active' => true]);
    Http::preventStrayRequests();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'Hosted media post',
            'media' => [[
                'id' => 'media-1',
                'path' => 'assets/foo.jpg',
                'url' => 'https://cdn.trypost.test/assets/foo.jpg',
                'type' => 'image',
            ]],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertCreated();

    expect(data_get(Post::where('content', 'Hosted media post')->firstOrFail()->media, '0.path'))->toBe('assets/foo.jpg');
    expect(Media::where('mediable_id', $this->workspace->id)->count())->toBe(0);
});

it('downloads and hosts an external media url when updating a post', function () {
    Http::fake([
        '93.184.216.34/listing.jpg' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $this->post), [
            'status' => 'draft',
            'media' => [['url' => 'https://93.184.216.34/listing.jpg']],
        ])
        ->assertOk();

    $media = $this->post->fresh()->media;

    expect($media)->toHaveCount(1)
        ->and(data_get($media, '0.path'))->not->toBeNull()
        ->and(data_get($media, '0.url'))->not->toContain('93.184.216.34');
});

it('rejects updating a post when an external media url cannot be fetched', function () {
    Http::fake(['93.184.216.34/missing.jpg' => Http::response(null, 404)]);

    $original = $this->post->fresh()->media;

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $this->post), [
            'status' => 'draft',
            'media' => [['url' => 'https://93.184.216.34/missing.jpg']],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media']);

    expect($this->post->fresh()->media)->toBe($original);
});

it('accepts and persists media alt text on create', function () {
    $this->socialAccount->update(['is_active' => true]);
    Http::preventStrayRequests();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'Alt text post',
            'media' => [[
                'id' => 'media-1',
                'path' => 'assets/foo.jpg',
                'url' => 'https://cdn.trypost.test/assets/foo.jpg',
                'type' => 'image',
                'meta' => ['alt_text' => 'A description of the photo'],
            ]],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertCreated();

    $media = Post::where('content', 'Alt text post')->firstOrFail()->media;

    expect(data_get($media, '0.meta.alt_text'))->toBe('A description of the photo');
});

it('accepts and persists media alt text on update', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $this->post), [
            'status' => 'draft',
            'media' => [[
                'id' => 'media-1',
                'path' => 'assets/foo.jpg',
                'url' => 'https://cdn.trypost.test/assets/foo.jpg',
                'type' => 'image',
                'meta' => ['alt_text' => 'Updated alt text'],
            ]],
        ])
        ->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBe('Updated alt text');
});

it('preserves every media meta key on update, not just alt_text', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $this->post), [
            'status' => 'draft',
            'media' => [[
                'id' => 'media-1',
                'path' => 'assets/foo.jpg',
                'url' => 'https://cdn.trypost.test/assets/foo.jpg',
                'type' => 'image',
                'meta' => [
                    'width' => 1920,
                    'height' => 1080,
                    'duration' => 30,
                    'alt_text' => 'A description of the photo',
                ],
            ]],
        ])
        ->assertOk();

    expect(data_get($this->post->fresh()->media, '0.meta'))->toMatchArray([
        'width' => 1920,
        'height' => 1080,
        'duration' => 30,
        'alt_text' => 'A description of the photo',
    ]);
});

it('rejects media alt text over 2000 characters', function () {
    $this->socialAccount->update(['is_active' => true]);
    Http::preventStrayRequests();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), [
            'content' => 'Alt text too long post',
            'media' => [[
                'id' => 'media-1',
                'path' => 'assets/foo.jpg',
                'url' => 'https://cdn.trypost.test/assets/foo.jpg',
                'type' => 'image',
                'meta' => ['alt_text' => str_repeat('a', 2001)],
            ]],
            'platforms' => [
                ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['media.0.meta']);

    expect(Post::where('content', 'Alt text too long post')->exists())->toBeFalse();
});
