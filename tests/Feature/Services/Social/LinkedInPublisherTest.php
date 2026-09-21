<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\LinkedInPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\LinkCard\LinkCardFetcher;
use App\Services\Social\LinkCard\LinkCardMetadata;
use App\Services\Social\LinkedInPublisher;
use Illuminate\Support\Facades\Http;
use RuntimeException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'abc123xyz',
        'username' => 'johndoe',
        'token_expires_at' => now()->addDays(60),
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from LinkedIn!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
    ]);

    $this->publisher = new LinkedInPublisher;
});

test('linkedin publisher can publish text-only post', function () {
    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('urn:li:share:1234567890');
    expect($result['url'])->toContain('linkedin.com/feed/update/urn:li:share:1234567890');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/rest/posts')
            && $request['author'] === 'urn:li:person:abc123xyz'
            && $request['commentary'] === 'Hello from LinkedIn!'
            && $request['visibility'] === 'PUBLIC'
            && ! isset($request['content']);
    });
});

test('linkedin publisher uses correct headers', function () {
    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization')
            && $request->hasHeader('X-Restli-Protocol-Version')
            && $request->hasHeader('LinkedIn-Version')
            && str_starts_with($request->header('Authorization')[0], 'Bearer ');
    });
});

test('linkedin publisher throws exception on api error', function () {
    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response([
            'message' => 'Invalid request',
            'status' => 400,
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('linkedin publisher throws token expired exception on auth error after retry', function () {
    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response([
            'code' => 'EXPIRED_ACCESS_TOKEN',
            'message' => 'The token used in the request has expired',
        ], 401),
        config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is invalid',
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('linkedin publisher refreshes token when expired', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 5184000,
        ], 200),
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'oauth/v2/accessToken');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('linkedin publisher throws exception when no refresh token available', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => null,
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'No refresh token available for LinkedIn account');
});

test('linkedin publisher throws TokenExpiredException when refresh_token is rejected', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'The refresh token is invalid',
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class, 'The refresh token is invalid');
});

test('linkedin publisher handles empty content', function () {
    $this->post->update(['content' => '']);

    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:1234567890');

    Http::assertSent(function ($request) {
        return $request['commentary'] === '';
    });
});

test('linkedin publisher can publish post with image', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms/upload/v2/pic/0/C5622AQFake';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'image' => 'urn:li:image:C5622AQFakeImageUrn',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:9876543210']);
        }

        // Media download fallback
        return Http::response('fake-image-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:9876543210');
    expect($result['url'])->toContain('linkedin.com/feed/update/urn:li:share:9876543210');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/images'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts')
        && isset($request['content']['media']['id'])
    );
});

test('linkedin publisher sends the real alt text on a single image post', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
                'meta' => ['alt_text' => 'A golden retriever catching a frisbee in the park'],
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms/upload/v2/pic/0/C5622AQFake';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'image' => 'urn:li:image:C5622AQFakeImageUrn',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:altsingle']);
        }

        // Media download fallback
        return Http::response('fake-image-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        return data_get($request->data(), 'content.media.altText') === 'A golden retriever catching a frisbee in the park'
            && data_get($request->data(), 'content.media.altText') !== 'test.jpg';
    });
});

test('linkedin publisher omits alt text on a single image post when none is set', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms/upload/v2/pic/0/C5622AQFake';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'image' => 'urn:li:image:C5622AQFakeImageUrn',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:altnone']);
        }

        // Media download fallback
        return Http::response('fake-image-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        $media = data_get($request->data(), 'content.media');

        return is_array($media)
            && $media['id'] === 'urn:li:image:C5622AQFakeImageUrn'
            && ! array_key_exists('altText', $media);
    });
});

test('linkedin publisher truncates single image alt text to the platform max length', function () {
    $longAlt = str_repeat('a', 4200);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
                'meta' => ['alt_text' => $longAlt],
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms/upload/v2/pic/0/C5622AQFake';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'image' => 'urn:li:image:C5622AQFakeImageUrn',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:alttrunc']);
        }

        // Media download fallback
        return Http::response('fake-image-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        return data_get($request->data(), 'content.media.altText') === str_repeat('a', 4086);
    });
});

test('linkedin publisher can publish carousel with multiple images', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-1',
                'path' => 'media/2026-01/carousel-1.jpg',
                'url' => 'https://example.com/media/2026-01/carousel-1.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'carousel-1.jpg',
            ],
            [
                'id' => 'test-media-2',
                'path' => 'media/2026-01/carousel-2.jpg',
                'url' => 'https://example.com/media/2026-01/carousel-2.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'carousel-2.jpg',
            ],
            [
                'id' => 'test-media-3',
                'path' => 'media/2026-01/carousel-3.jpg',
                'url' => 'https://example.com/media/2026-01/carousel-3.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'carousel-3.jpg',
            ],
        ],
    ]);

    $uploadUrls = [
        'https://www.linkedin.com/dms/upload/v2/pic/carousel/1',
        'https://www.linkedin.com/dms/upload/v2/pic/carousel/2',
        'https://www.linkedin.com/dms/upload/v2/pic/carousel/3',
    ];

    $imageUrns = [
        'urn:li:image:CarouselImageUrn1',
        'urn:li:image:CarouselImageUrn2',
        'urn:li:image:CarouselImageUrn3',
    ];

    $initCallCount = 0;

    Http::fake(function ($request) use ($uploadUrls, $imageUrns, &$initCallCount) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            $idx = $initCallCount % 3;
            $initCallCount++;

            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrls[$idx],
                    'image' => $imageUrns[$idx],
                ],
            ], 200);
        }

        // Image PUT upload
        foreach ($uploadUrls as $uploadUrl) {
            if ($url === $uploadUrl) {
                return Http::response(null, 201);
            }
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:carousel999']);
        }

        // Media download fallback
        return Http::response('fake-image-content', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:carousel999');
    expect($result['url'])->toContain('linkedin.com/feed/update/urn:li:share:carousel999');

    // Each multiImage entry must carry the image URN under `id` (LinkedIn rejects `media`).
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }
        $images = data_get($request->data(), 'content.multiImage.images');

        return is_array($images)
            && count($images) === 3
            && data_get($images, '0.id') === 'urn:li:image:CarouselImageUrn1'
            && ! array_key_exists('media', $images[0]);
    });
});

test('linkedin publisher sends per-image alt text on a carousel, not the filename', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-1',
                'path' => 'media/2026-01/carousel-1.jpg',
                'url' => 'https://example.com/media/2026-01/carousel-1.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'carousel-1.jpg',
                'meta' => ['alt_text' => 'A sunset over the mountains'],
            ],
            [
                'id' => 'test-media-2',
                'path' => 'media/2026-01/carousel-2.jpg',
                'url' => 'https://example.com/media/2026-01/carousel-2.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'carousel-2.jpg',
            ],
        ],
    ]);

    $uploadUrls = [
        'https://www.linkedin.com/dms/upload/v2/pic/carousel/alt/1',
        'https://www.linkedin.com/dms/upload/v2/pic/carousel/alt/2',
    ];

    $imageUrns = [
        'urn:li:image:CarouselAltUrn1',
        'urn:li:image:CarouselAltUrn2',
    ];

    $initCallCount = 0;

    Http::fake(function ($request) use ($uploadUrls, $imageUrns, &$initCallCount) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            $idx = $initCallCount % 2;
            $initCallCount++;

            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrls[$idx],
                    'image' => $imageUrns[$idx],
                ],
            ], 200);
        }

        foreach ($uploadUrls as $uploadUrl) {
            if ($url === $uploadUrl) {
                return Http::response(null, 201);
            }
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:carouselalt']);
        }

        // Media download fallback
        return Http::response('fake-image-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    // The first image carries the user's real alt text (not the filename); the
    // second has no alt_text set, so its `altText` key is omitted entirely.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        $images = data_get($request->data(), 'content.multiImage.images');

        return is_array($images)
            && count($images) === 2
            && data_get($images, '0.altText') === 'A sunset over the mountains'
            && ! array_key_exists('altText', $images[1]);
    });
});

test('linkedin publisher caps a carousel at the platform max images', function () {
    $media = [];
    for ($i = 1; $i <= 12; $i++) {
        $media[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/carousel-{$i}.jpg",
            'url' => "https://example.com/media/2026-01/carousel-{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "carousel-{$i}.jpg",
        ];
    }
    $this->post->update(['media' => $media]);

    $initCallCount = 0;

    Http::fake(function ($request) use (&$initCallCount) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            $initCallCount++;

            return Http::response([
                'value' => [
                    'uploadUrl' => "https://www.linkedin.com/dms/upload/v2/pic/carousel/{$initCallCount}",
                    'image' => "urn:li:image:CarouselImageUrn{$initCallCount}",
                ],
            ], 200);
        }

        if (str_contains($url, '/dms/upload/v2/pic/carousel/')) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:capped']);
        }

        return Http::response('fake-image-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    // Only the first 10 images (LinkedIn::maxImages) are uploaded; the extra 2 are dropped.
    expect($initCallCount)->toBe(10);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        return count(data_get($request->data(), 'content.multiImage.images', [])) === 10;
    });
});

test('linkedin publisher can publish post with video', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test-video.mp4',
            ],
        ],
    ]);

    $chunkUploadUrl = 'https://www.linkedin.com/dms/upload/v2/chunk/video/1';

    Http::fake(function ($request) use ($chunkUploadUrl) {
        $url = $request->url();

        if (str_contains($url, 'initializeUpload') && str_contains($url, '/rest/videos')) {
            return Http::response([
                'value' => [
                    'video' => 'urn:li:video:FakeVideoUrn',
                    'uploadToken' => 'upload-token-abc',
                    'uploadInstructions' => [
                        [
                            'uploadUrl' => $chunkUploadUrl,
                            'firstByte' => 0,
                            'lastByte' => 1023,
                        ],
                    ],
                ],
            ], 200);
        }

        if ($url === $chunkUploadUrl) {
            return Http::response(null, 200, ['etag' => '"etag-abc123"']);
        }

        if (str_contains($url, 'finalizeUpload') && str_contains($url, '/rest/videos')) {
            return Http::response(null, 200);
        }

        if (str_contains($url, '/rest/videos/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:1111111111']);
        }

        // Media download fallback
        return Http::response(str_repeat('x', 1024), 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:1111111111');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'initializeUpload'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'finalizeUpload'));
    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts'));
});

test('linkedin publisher never sends altText on a single video post even if the video carries alt text', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test-video.mp4',
                'meta' => ['alt_text' => 'alt text must not be sent on a video payload'],
            ],
        ],
    ]);

    $chunkUploadUrl = 'https://www.linkedin.com/dms/upload/v2/chunk/video/1';

    Http::fake(function ($request) use ($chunkUploadUrl) {
        $url = $request->url();

        if (str_contains($url, 'initializeUpload') && str_contains($url, '/rest/videos')) {
            return Http::response([
                'value' => [
                    'video' => 'urn:li:video:FakeVideoUrn',
                    'uploadToken' => 'upload-token-abc',
                    'uploadInstructions' => [
                        ['uploadUrl' => $chunkUploadUrl, 'firstByte' => 0, 'lastByte' => 1023],
                    ],
                ],
            ], 200);
        }

        if ($url === $chunkUploadUrl) {
            return Http::response(null, 200, ['etag' => '"etag-abc123"']);
        }

        if (str_contains($url, 'finalizeUpload') && str_contains($url, '/rest/videos')) {
            return Http::response(null, 200);
        }

        if (str_contains($url, '/rest/videos/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:videonoalt']);
        }

        return Http::response(str_repeat('x', 1024), 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        $media = data_get($request->data(), 'content.media');

        return is_array($media)
            && data_get($media, 'id') === 'urn:li:video:FakeVideoUrn'
            && ! array_key_exists('altText', $media);
    });
});

test('linkedin publisher uploads a video across multiple chunks', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test-video.mp4',
            ],
        ],
    ]);

    $chunkBase = 'https://www.linkedin.com/dms/upload/v2/chunk/video/';
    $chunkPuts = 0;

    Http::fake(function ($request) use ($chunkBase, &$chunkPuts) {
        $url = $request->url();

        if (str_contains($url, 'initializeUpload') && str_contains($url, '/rest/videos')) {
            return Http::response([
                'value' => [
                    'video' => 'urn:li:video:FakeVideoUrn',
                    'uploadToken' => 'upload-token-abc',
                    'uploadInstructions' => [
                        ['uploadUrl' => $chunkBase.'0', 'firstByte' => 0, 'lastByte' => 1023],
                        ['uploadUrl' => $chunkBase.'1', 'firstByte' => 1024, 'lastByte' => 2047],
                        ['uploadUrl' => $chunkBase.'2', 'firstByte' => 2048, 'lastByte' => 3071],
                    ],
                ],
            ], 200);
        }

        if (str_starts_with($url, $chunkBase)) {
            $chunkPuts++;

            return Http::response(null, 200, ['etag' => '"etag-'.$chunkPuts.'"']);
        }

        if (str_contains($url, 'finalizeUpload') && str_contains($url, '/rest/videos')) {
            return Http::response(null, 200);
        }

        if (str_contains($url, '/rest/videos/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:multichunk']);
        }

        return Http::response(str_repeat('x', 4096), 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:multichunk');
    expect($chunkPuts)->toBe(3);

    // All three chunk etags are collected and sent to finalize.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'finalizeUpload')) {
            return false;
        }

        return count(data_get($request->data(), 'finalizeUploadRequest.uploadedPartIds', [])) === 3;
    });
});

test('linkedin publisher can publish a document (pdf carousel) with a title', function () {
    $this->postPlatform->update([
        'content_type' => ContentType::LinkedInPost,
        'meta' => ['document_title' => 'My Slides'],
    ]);
    $this->post->update([
        'media' => [
            [
                'id' => 'doc-media-1',
                'path' => 'media/2026-01/deck.pdf',
                'url' => 'https://example.com/media/2026-01/deck.pdf',
                'mime_type' => 'application/pdf',
                'original_filename' => 'deck.pdf',
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms-uploads/document/0';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'document' => 'urn:li:document:FakeDocUrn',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:doc999']);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:doc999');
    expect($result['url'])->toContain('linkedin.com/feed/update/urn:li:share:doc999');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/documents') && str_contains($request->url(), 'initializeUpload'));
    Http::assertSent(function ($request) use ($uploadUrl) {
        return $request->url() === $uploadUrl
            && $request->hasHeader('Content-Type', 'application/pdf');
    });
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        return data_get($request->data(), 'content.media.id') === 'urn:li:document:FakeDocUrn'
            && data_get($request->data(), 'content.media.title') === 'My Slides';
    });
});

test('linkedin publisher document title falls back to the file name', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'media' => [
            [
                'id' => 'doc-media-1',
                'path' => 'media/2026-01/whitepaper.pdf',
                'url' => 'https://example.com/media/2026-01/whitepaper.pdf',
                'mime_type' => 'application/pdf',
                'original_filename' => 'whitepaper.pdf',
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms-uploads/document/1';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response(['value' => ['uploadUrl' => $uploadUrl, 'document' => 'urn:li:document:Doc2']], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:doc2']);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/rest/posts')) {
            return false;
        }

        return data_get($request->data(), 'content.media.title') === 'whitepaper.pdf';
    });
});

test('linkedin publisher waits for document processing before posting', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'media' => [
            [
                'id' => 'doc-media-1',
                'path' => 'media/2026-01/deck.pdf',
                'url' => 'https://example.com/media/2026-01/deck.pdf',
                'mime_type' => 'application/pdf',
                'original_filename' => 'deck.pdf',
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms-uploads/document/2';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response(['value' => ['uploadUrl' => $uploadUrl, 'document' => 'urn:li:document:Doc3']], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'AVAILABLE'], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:doc3']);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/documents/urn'));
});

test('linkedin publisher throws and does not post when document processing fails', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'media' => [
            [
                'id' => 'doc-media-1',
                'path' => 'media/2026-01/deck.pdf',
                'url' => 'https://example.com/media/2026-01/deck.pdf',
                'mime_type' => 'application/pdf',
                'original_filename' => 'deck.pdf',
            ],
        ],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms-uploads/document/fail';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response(['value' => ['uploadUrl' => $uploadUrl, 'document' => 'urn:li:document:Fail']], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'PROCESSING_FAILED'], 200);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'LinkedIn document processing failed');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/posts'));
});

test('linkedin publisher throws and does not post when document processing never completes', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'media' => [[
            'id' => 'doc-media-1', 'path' => 'media/2026-01/deck.pdf',
            'url' => 'https://example.com/media/2026-01/deck.pdf',
            'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf',
        ]],
    ]);

    $uploadUrl = 'https://www.linkedin.com/dms-uploads/document/timeout';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/documents') && str_contains($url, 'initializeUpload')) {
            return Http::response(['value' => ['uploadUrl' => $uploadUrl, 'document' => 'urn:li:document:Timeout']], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/documents/')) {
            return Http::response(['status' => 'PROCESSING'], 200);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    $publisher = new class extends LinkedInPublisher
    {
        protected function processingMaxAttempts(): int
        {
            return 2;
        }

        protected function processingPollSeconds(): int
        {
            return 0;
        }
    };

    expect(fn () => $publisher->publish($this->postPlatform))
        ->toThrow(Exception::class, 'processing did not complete in time');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/posts'));
});

test('linkedin publisher throws and does not post when document init fails', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'media' => [[
            'id' => 'doc-media-1', 'path' => 'media/2026-01/deck.pdf',
            'url' => 'https://example.com/media/2026-01/deck.pdf',
            'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf',
        ]],
    ]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/rest/documents') && str_contains($request->url(), 'initializeUpload')) {
            return Http::response(['message' => 'Invalid request', 'status' => 400], 400);
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))->toThrow(Exception::class);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/posts'));
});

test('linkedin publisher throws when document init response is missing the urn', function () {
    $this->postPlatform->update(['content_type' => ContentType::LinkedInPost]);
    $this->post->update([
        'media' => [[
            'id' => 'doc-media-1', 'path' => 'media/2026-01/deck.pdf',
            'url' => 'https://example.com/media/2026-01/deck.pdf',
            'mime_type' => 'application/pdf', 'original_filename' => 'deck.pdf',
        ]],
    ]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/rest/documents') && str_contains($request->url(), 'initializeUpload')) {
            return Http::response(['value' => []], 200); // no uploadUrl / document
        }

        return Http::response('fake-pdf-bytes', 200);
    });

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(LinkedInPublishException::class, 'did not accept the document upload');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/posts'));
});

test('linkedin publisher treats a 401 response without an error code as a token error', function () {
    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(['message' => 'Unauthorized'], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('linkedin publisher does NOT rotate the token when it is only expiring soon but still valid', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->addMinutes(5),
        'refresh_token' => 'refresh-token-123',
    ]);
    $originalAccessToken = $this->socialAccount->access_token;

    Http::fake([
        config('trypost.platforms.linkedin.oauth_api').'/oauth/v2/accessToken' => Http::response([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ], 200),
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, ['x-restli-id' => 'urn:li:share:soon']),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:soon');

    // A still-valid token is used as-is — the single-use refresh_token is not rotated.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'oauth/v2/accessToken'));
    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe($originalAccessToken);
});

test('linkedin publisher falls back to an empty id and null url when the post id header is missing', function () {
    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('');
    expect($result['url'])->toBeNull();
});

test('linkedin publisher keeps links intact', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    $this->post->update(['content' => 'New post: https://trypost.it/blog']);

    $this->mock(LinkCardFetcher::class)->shouldReceive('fetch')->once()->andReturn(null);

    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:1234567890',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts')
        && $request['commentary'] === 'New post: https://trypost.it/blog'
        && ! isset($request['content']));
});

test('linkedin publisher sends an article card for a text post that contains a link', function () {
    $this->post->update(['content' => 'Read this https://example.com/article today.']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            imageUrl: null,
        ));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:article1',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $article = $request['content']['article'] ?? null;

        return str_contains($request->url(), '/rest/posts')
            && $request['commentary'] === 'Read this https://example.com/article today.'
            && $article['source'] === 'https://example.com/article'
            && $article['title'] === 'The Article'
            && $article['description'] === 'A great read'
            && ! isset($article['thumbnail']);
    });
});

test('linkedin publisher skips the article card when the page has no title', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: '   ',
            description: 'Only a description',
            imageUrl: null,
        ));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:notitle',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts')
        && ! isset($request['content']));
});

test('linkedin publisher prefers attached media over a link card', function () {
    $this->post->update([
        'content' => 'see https://example.com/article',
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    $this->mock(LinkCardFetcher::class)->shouldNotReceive('fetch');

    $uploadUrl = 'https://www.linkedin.com/dms/upload/v2/pic/0/C5622AQFake';

    Http::fake(function ($request) use ($uploadUrl) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'image' => 'urn:li:image:C5622AQFakeImageUrn',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:mediawins']);
        }

        return Http::response('fake-image-content', 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts')
        && isset($request['content']['media']['id'])
        && ! isset($request['content']['article']));
});

test('linkedin publisher uploads the article thumbnail', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            imageUrl: 'https://93.184.216.34/card.jpg',
        ));

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->once()
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'li_thumb_'), fn ($path) => file_put_contents($path, 'thumb-bytes')));

    $jpeg = linkedInJpegBytes();
    $uploadUrl = 'https://www.linkedin.com/dms/upload/v2/pic/0/article-thumb';

    Http::fake(function ($request) use ($uploadUrl, $jpeg) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return Http::response([
                'value' => [
                    'uploadUrl' => $uploadUrl,
                    'image' => 'urn:li:image:articleThumb',
                ],
            ], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:withthumb']);
        }

        return Http::response($jpeg, 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $article = $request['content']['article'] ?? null;

        return str_contains($request->url(), '/rest/posts')
            && $article['thumbnail'] === 'urn:li:image:articleThumb'
            && $article['source'] === 'https://example.com/article';
    });
});

test('linkedin publisher still publishes the article when the thumbnail cannot be fetched', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            imageUrl: 'https://93.184.216.34/missing.jpg',
        ));

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:nothumb']);
        }

        return Http::response('', 404);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $article = $request['content']['article'] ?? null;

        return str_contains($request->url(), '/rest/posts')
            && $article['title'] === 'The Article'
            && ! isset($article['thumbnail']);
    });
});

test('linkedin publisher publishes the text when the link preview lookup fails', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andThrow(new RuntimeException('scrape failed'));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:lookupfailed',
        ]),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('urn:li:share:lookupfailed');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/rest/posts')
        && $request['commentary'] === 'Read this https://example.com/article'
        && ! isset($request['content']));
});

test('linkedin publisher blocks the article thumbnail when the image points at a private address', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            imageUrl: 'http://127.0.0.1/evil.jpg',
        ));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:private',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $article = $request['content']['article'] ?? null;

        return str_contains($request->url(), '/rest/posts')
            && $article['title'] === 'The Article'
            && ! isset($article['thumbnail']);
    });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('linkedin publisher does not follow a redirect on the article thumbnail', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            imageUrl: 'https://93.184.216.34/card.jpg',
        ));

    Http::fake([
        'https://93.184.216.34/card.jpg' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal.jpg']),
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:noredirect',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $article = $request['content']['article'] ?? null;

        return str_contains($request->url(), '/rest/posts')
            && $article['title'] === 'The Article'
            && ! isset($article['thumbnail']);
    });

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('linkedin publisher trims the article title and description to what the api accepts', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: str_repeat('t', 500),
            description: str_repeat('d', 5000),
            imageUrl: null,
        ));

    Http::fake([
        config('trypost.platforms.linkedin.api').'/rest/posts' => Http::response(null, 201, [
            'x-restli-id' => 'urn:li:share:trimmed',
        ]),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $article = $request['content']['article'] ?? null;

        return str_contains($request->url(), '/rest/posts')
            && mb_strlen($article['title']) === 399
            && mb_strlen($article['description']) === 4085;
    });
});

test('linkedin publisher skips the thumbnail when og:image does not point at an image', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            imageUrl: 'https://93.184.216.34/card.jpg',
        ));

    Http::fake(function ($request) {
        if (str_contains($request->url(), '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:html']);
        }

        return Http::response('<html><body>Not an image</body></html>', 200, ['Content-Type' => 'text/html']);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/rest/images'));
    Http::assertSent(function ($request) {
        $article = $request['content']['article'] ?? null;

        return str_contains($request->url(), '/rest/posts')
            && $article['title'] === 'The Article'
            && ! isset($article['thumbnail']);
    });
});

test('linkedin publisher refreshes the token when the thumbnail upload is rejected', function () {
    $this->post->update(['content' => 'Read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->twice()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            imageUrl: 'https://93.184.216.34/card.jpg',
        ));

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->twice()
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'li_thumb_'), fn ($path) => file_put_contents($path, 'thumb-bytes')));

    $jpeg = linkedInJpegBytes();
    $uploadUrl = 'https://www.linkedin.com/dms/upload/v2/pic/0/article-thumb';
    $imageInitCalls = 0;

    Http::fake(function ($request) use ($uploadUrl, $jpeg, &$imageInitCalls) {
        $url = $request->url();

        if (str_contains($url, '/rest/images')) {
            return ++$imageInitCalls === 1
                ? Http::response(['message' => 'Expired token'], 401)
                : Http::response(['value' => ['uploadUrl' => $uploadUrl, 'image' => 'urn:li:image:afterRefresh']], 200);
        }

        if ($url === $uploadUrl) {
            return Http::response(null, 201);
        }

        if (str_contains($url, '/oauth/v2/accessToken')) {
            return Http::response(['access_token' => 'new-access-token', 'refresh_token' => 'new-refresh-token', 'expires_in' => 5184000], 200);
        }

        if (str_contains($url, '/rest/posts')) {
            return Http::response(null, 201, ['x-restli-id' => 'urn:li:share:refreshed']);
        }

        return Http::response($jpeg, 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/oauth/v2/accessToken'));
    Http::assertSent(function ($request) {
        $article = $request['content']['article'] ?? null;

        return str_contains($request->url(), '/rest/posts')
            && $request->hasHeader('Authorization', 'Bearer new-access-token')
            && $article['thumbnail'] === 'urn:li:image:afterRefresh';
    });
});

function linkedInJpegBytes(): string
{
    $image = imagecreatetruecolor(8, 8);
    ob_start();
    imagejpeg($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes === false ? '' : $bytes;
}
