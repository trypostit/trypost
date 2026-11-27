<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ContentLimitException;
use App\Exceptions\Social\MissingPermissionException;
use App\Exceptions\Social\ThreadsMediaContainerNotFoundException;
use App\Exceptions\Social\ThreadsPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ThreadsPublisher;
use App\Support\Social\ThreadProgress;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function () {
    Sleep::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->threads()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => '123456789',
        'username' => 'testuser',
        'token_expires_at' => now()->addDays(60),
    ]);

    $this->post = Post::factory()->forAccount($this->socialAccount)->create([
        'user_id' => $this->user->id,
        'content' => 'Hello from Threads!',
        'content_type' => ContentType::ThreadsPost,
    ]);
    $this->post->setRelation('socialAccount', $this->socialAccount);

    $this->publisher = new ThreadsPublisher;
});

test('threads publisher can publish text-only post', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->post);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('post-123456789');
    expect($result['url'])->toBe('https://www.threads.net/@testuser/post/ABC123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/threads')
            && str_contains($request->url(), '123456789');
    });
});

test('threads publisher keeps the published id when the permalink lookup fails', function (string $failure) {
    $base = config('trypost.platforms.threads.graph_api');

    Http::fake([
        "{$base}/123456789/threads" => Http::response(['id' => 'container-123']),
        "{$base}/container-123*" => Http::response(['status' => 'FINISHED']),
        "{$base}/123456789/threads_publish" => Http::response(['id' => 'post-123']),
        "{$base}/post-123*" => match ($failure) {
            'connection' => Http::failedConnection(),
            'http' => Http::response(['error' => ['message' => 'Unavailable']], 500),
            'missing link' => Http::response([]),
        },
    ]);

    expect($this->publisher->publish($this->post))->toBe([
        'id' => 'post-123',
        'url' => null,
    ]);

    expect(Http::recorded(fn ($request): bool => $request->method() === 'POST'
        && Str::endsWith($request->url(), '/threads_publish')))->toHaveCount(1);
})->with(['connection', 'http', 'missing link']);

test('threads publisher can publish image post', function () {
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

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->post);

    expect($result['id'])->toBe('post-123456789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/threads');
    });
});

test('threads publisher recreates a missing image container before retrying publication', function () {
    Log::spy();

    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    $containerCreations = 0;
    $publicationAttempts = 0;

    Http::fake(function ($request) use (&$containerCreations, &$publicationAttempts) {
        if (str_ends_with($request->url(), '/123456789/threads')) {
            $containerCreations++;

            return Http::response(['id' => "container-{$containerCreations}"], 200);
        }

        if (str_contains($request->url(), '/container-')) {
            return Http::response(['status' => 'FINISHED'], 200);
        }

        if (str_ends_with($request->url(), '/123456789/threads_publish')) {
            $publicationAttempts++;

            if ($publicationAttempts === 1) {
                return Http::response([
                    'error' => [
                        'message' => 'The requested resource does not exist',
                        'code' => 24,
                        'error_subcode' => 4279009,
                    ],
                ], 400);
            }

            return Http::response(['id' => 'post-after-retry'], 200);
        }

        return Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/RETRY',
        ], 200);
    });

    $result = $this->publisher->publish($this->post);

    expect($result['id'])->toBe('post-after-retry')
        ->and($containerCreations)->toBe(2)
        ->and($publicationAttempts)->toBe(2);

    Sleep::assertSequence([
        Sleep::for(2)->seconds(),
        Sleep::for(2)->seconds(),
    ]);

    Log::shouldHaveReceived('warning')->once();
    Log::shouldNotHaveReceived('error');
});

test('threads publisher does not retry a missing media response from container creation', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    $containerCreations = 0;

    Http::fake(function ($request) use (&$containerCreations) {
        $containerCreations++;

        return Http::response([
            'error' => [
                'message' => 'The requested resource does not exist',
                'code' => 24,
                'error_subcode' => 4279009,
            ],
        ], 400);
    });

    expect(fn () => $this->publisher->publish($this->post))
        ->toThrow(ThreadsPublishException::class);

    expect($containerCreations)->toBe(1);
});

test('threads publisher stops after three missing media containers', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    $containerCreations = 0;
    $publicationAttempts = 0;

    Http::fake(function ($request) use (&$containerCreations, &$publicationAttempts) {
        if (str_ends_with($request->url(), '/123456789/threads')) {
            $containerCreations++;

            return Http::response(['id' => "container-{$containerCreations}"], 200);
        }

        if (str_contains($request->url(), '/container-')) {
            return Http::response(['status' => 'FINISHED'], 200);
        }

        if (str_ends_with($request->url(), '/123456789/threads_publish')) {
            $publicationAttempts++;

            return Http::response([
                'error' => [
                    'message' => 'The requested resource does not exist',
                    'code' => 24,
                    'error_subcode' => 4279009,
                ],
            ], 400);
        }

        return Http::response([], 500);
    });

    try {
        $this->publisher->publish($this->post);
        $this->fail('Expected ThreadsPublishException was not thrown.');
    } catch (ThreadsPublishException $exception) {
        expect($exception)->toBeInstanceOf(ThreadsMediaContainerNotFoundException::class);
    }

    expect($containerCreations)->toBe(3)
        ->and($publicationAttempts)->toBe(3);
});

test('threads publisher does not retry unrelated client errors', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    $containerCreations = 0;
    $publicationAttempts = 0;

    Http::fake(function ($request) use (&$containerCreations, &$publicationAttempts) {
        if (str_ends_with($request->url(), '/123456789/threads')) {
            $containerCreations++;

            return Http::response(['id' => 'container-1'], 200);
        }

        if (str_contains($request->url(), '/container-1')) {
            return Http::response(['status' => 'FINISHED'], 200);
        }

        if (str_ends_with($request->url(), '/123456789/threads_publish')) {
            $publicationAttempts++;

            return Http::response([
                'error' => [
                    'message' => 'Invalid parameter',
                    'code' => 100,
                ],
            ], 400);
        }

        return Http::response([], 500);
    });

    expect(fn () => $this->publisher->publish($this->post))
        ->toThrow(ThreadsPublishException::class, 'Invalid parameter');

    expect($containerCreations)->toBe(1)
        ->and($publicationAttempts)->toBe(1);
});

test('threads publisher can publish video post', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test.mp4',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->post);

    expect($result['id'])->toBe('post-123456789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/threads');
    });
});

test('threads publisher can publish carousel', function () {
    $mediaItems = [];
    for ($i = 0; $i < 3; $i++) {
        $mediaItems[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/test-image-{$i}.jpg",
            'url' => "https://example.com/media/2026-01/test-image-{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "test-{$i}.jpg",
        ];
    }
    $this->post->update([
        'media' => $mediaItems]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->post);

    expect($result['id'])->toBe('post-123456789');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), '/threads');
    });
});

test('threads publisher waits for the final carousel container before publishing', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-1',
                'path' => 'media/2026-01/test-image-1.jpg',
                'url' => 'https://example.com/media/2026-01/test-image-1.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test-1.jpg',
            ],
            [
                'id' => 'test-media-2',
                'path' => 'media/2026-01/test-image-2.jpg',
                'url' => 'https://example.com/media/2026-01/test-image-2.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test-2.jpg',
            ],
        ],
    ]);

    $containerCreations = 0;
    $requestOrder = [];

    Http::fake(function ($request) use (&$containerCreations, &$requestOrder) {
        if (str_ends_with($request->url(), '/123456789/threads')) {
            $containerCreations++;
            $containerId = $containerCreations <= 2
                ? "child-{$containerCreations}"
                : 'carousel-final';
            $requestOrder[] = "create:{$containerId}";

            return Http::response(['id' => $containerId], 200);
        }

        if (str_contains($request->url(), '/child-')) {
            $requestOrder[] = 'status:child';

            return Http::response(['status' => 'FINISHED'], 200);
        }

        if (str_contains($request->url(), '/carousel-final')) {
            $requestOrder[] = 'status:carousel-final';

            return Http::response(['status' => 'FINISHED'], 200);
        }

        if (str_ends_with($request->url(), '/123456789/threads_publish')) {
            $requestOrder[] = 'publish';

            return Http::response(['id' => 'carousel-post'], 200);
        }

        return Http::response(['permalink' => 'https://www.threads.net/carousel'], 200);
    });

    $this->publisher->publish($this->post);

    expect($requestOrder)
        ->toContain('status:carousel-final')
        ->toContain('publish');

    $finalStatusIndex = array_search('status:carousel-final', $requestOrder, true);
    $publishIndex = array_search('publish', $requestOrder, true);

    expect($finalStatusIndex)->toBeInt()
        ->and($publishIndex)->toBeInt()
        ->and($finalStatusIndex)->toBeLessThan($publishIndex);
});

test('threads publisher recreates the complete carousel after a missing final container', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-1',
                'path' => 'media/2026-01/test-image-1.jpg',
                'url' => 'https://example.com/media/2026-01/test-image-1.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test-1.jpg',
            ],
            [
                'id' => 'test-media-2',
                'path' => 'media/2026-01/test-image-2.jpg',
                'url' => 'https://example.com/media/2026-01/test-image-2.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test-2.jpg',
            ],
        ],
    ]);

    $childCreations = 0;
    $carouselCreations = 0;
    $publicationAttempts = 0;

    Http::fake(function ($request) use (&$childCreations, &$carouselCreations, &$publicationAttempts) {
        if (str_ends_with($request->url(), '/123456789/threads')) {
            if ($request['media_type'] === 'CAROUSEL') {
                $carouselCreations++;

                return Http::response(['id' => "carousel-{$carouselCreations}"], 200);
            }

            $childCreations++;

            return Http::response(['id' => "child-{$childCreations}"], 200);
        }

        if (str_contains($request->url(), '/child-') || str_contains($request->url(), '/carousel-')) {
            return Http::response(['status' => 'FINISHED'], 200);
        }

        if (str_ends_with($request->url(), '/123456789/threads_publish')) {
            $publicationAttempts++;

            if ($publicationAttempts === 1) {
                return Http::response([
                    'error' => [
                        'message' => 'The requested resource does not exist',
                        'code' => 24,
                        'error_subcode' => 4279009,
                    ],
                ], 400);
            }

            return Http::response(['id' => 'carousel-post'], 200);
        }

        return Http::response(['permalink' => 'https://www.threads.net/carousel'], 200);
    });

    $result = $this->publisher->publish($this->post);

    expect($result['id'])->toBe('carousel-post')
        ->and($childCreations)->toBe(4)
        ->and($carouselCreations)->toBe(2)
        ->and($publicationAttempts)->toBe(2);
});

test('threads publisher throws exception on api error', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'error' => [
                'message' => 'Invalid parameter',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->post))
        ->toThrow(Exception::class);
});

test('threads publisher throws token expired exception on auth error', function () {
    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'error' => [
                'message' => 'Error validating access token',
                'type' => 'OAuthException',
                'code' => 190,
            ],
        ], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->post))
        ->toThrow(TokenExpiredException::class);
});

test('threads publisher refreshes token when expired', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        'https://graph.threads.net/refresh_access_token*' => Http::response([
            'access_token' => 'new-long-lived-token',
            'expires_in' => 5184000,
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $this->publisher->publish($this->post);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'refresh_access_token');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-long-lived-token');
});

test('threads publisher throws TokenExpiredException when refresh_token is rejected', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        'https://graph.threads.net/refresh_access_token*' => Http::response([
            'error' => ['message' => 'Token is invalid', 'code' => 190],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->post))
        ->toThrow(TokenExpiredException::class, 'Token is invalid');
});

test('threads publisher waits for media processing', function () {
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

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::sequence()
            ->push(['status' => 'IN_PROGRESS'], 200)
            ->push(['status' => 'IN_PROGRESS'], 200)
            ->push(['status' => 'FINISHED'], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-123456789',
        ], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->post);

    expect($result['id'])->toBe('post-123456789');
});

test('threads publisher handles media processing error', function () {
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

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'ERROR',
            'error_message' => 'Media upload failed',
        ], 200),
    ]);

    expect(fn () => $this->publisher->publish($this->post))
        ->toThrow(Exception::class, 'Threads media processing failed');
});

test('threads publisher throws exception for text post with null content', function () {
    $this->post->update(['content' => null]);

    expect(fn () => $this->publisher->publish($this->post))
        ->toThrow(Exception::class, 'Threads text posts require content');
});

test('threads publisher can publish image with null content', function () {
    $this->post->update([
        'content' => null,
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

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'media-123',
        ], 200),
        'https://graph.threads.net/v1.0/media-123*' => Http::response([
            'permalink' => 'https://threads.net/@testuser/post/123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->post);

    expect($result['id'])->toBe('media-123');
});

test('threads publisher stops creating carousel items when Threads rejects the first one', function () {
    $mediaItems = [];
    for ($i = 0; $i < 3; $i++) {
        $mediaItems[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/fail-image-{$i}.jpg",
            'url' => "https://example.com/media/2026-01/fail-image-{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "fail-{$i}.jpg",
        ];
    }
    $this->post->update([
        'media' => $mediaItems]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'error' => [
                'message' => 'Upload failed',
                'type' => 'OAuthException',
                'code' => 100,
            ],
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->post))
        ->toThrow(ThreadsPublishException::class);
    Http::assertSentCount(1);
});

test('threads publisher sends capped alt text on single image container', function () {
    $longAlt = str_repeat('a', 1500);

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

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-alt-123',
        ], 200),
        'https://graph.threads.net/v1.0/post-alt-123*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ALT123',
        ], 200),
    ]);

    $this->publisher->publish($this->post);

    $expectedAlt = mb_substr($longAlt, 0, Platform::Threads->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        return str_ends_with($request->url(), '/123456789/threads')
            && data_get($request->data(), 'alt_text') === $expectedAlt
            && strlen($expectedAlt) === Platform::Threads->altTextMaxLength();
    });
});

test('threads publisher omits alt_text from single image container when no alt text is set', function () {
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

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-no-alt-123',
        ], 200),
        'https://graph.threads.net/v1.0/post-no-alt-123*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/NOALT123',
        ], 200),
    ]);

    $this->publisher->publish($this->post);

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/123456789/threads')
            && ! array_key_exists('alt_text', $request->data());
    });
});

test('threads publisher sends alt text on image and video carousel children', function () {
    $imageAlt = str_repeat('x', 1500);

    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-image',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
                'meta' => ['alt_text' => $imageAlt],
            ],
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test.mp4',
                'meta' => ['alt_text' => 'a cyclist crossing a bridge'],
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-carousel-alt-123',
        ], 200),
        'https://graph.threads.net/v1.0/post-carousel-alt-123*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/CAROUSELALT123',
        ], 200),
    ]);

    $this->publisher->publish($this->post);

    $expectedAlt = mb_substr($imageAlt, 0, Platform::Threads->altTextMaxLength());

    Http::assertSent(function ($request) use ($expectedAlt) {
        $data = $request->data();

        return str_ends_with($request->url(), '/123456789/threads')
            && data_get($data, 'image_url') !== null
            && data_get($data, 'alt_text') === $expectedAlt;
    });

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_ends_with($request->url(), '/123456789/threads')
            && data_get($data, 'video_url') !== null
            && data_get($data, 'alt_text') === 'a cyclist crossing a bridge';
    });
});

test('threads publisher sends alt text on a single video container', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test.mp4',
                'meta' => ['alt_text' => 'a cyclist crossing a bridge'],
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'post-video-alt-123',
        ], 200),
        'https://graph.threads.net/v1.0/post-video-alt-123*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/VIDEOALT123',
        ], 200),
    ]);

    $this->publisher->publish($this->post);

    Http::assertSent(function ($request) {
        $data = $request->data();

        return str_ends_with($request->url(), '/123456789/threads')
            && data_get($data, 'media_type') === 'VIDEO'
            && data_get($data, 'alt_text') === 'a cyclist crossing a bridge';
    });
});

test('threads publisher can publish video with null content', function () {
    $this->post->update([
        'content' => null,
        'media' => [
            [
                'id' => 'test-media-video',
                'path' => 'media/2026-01/test-video.mp4',
                'url' => 'https://example.com/media/2026-01/test-video.mp4',
                'mime_type' => 'video/mp4',
                'original_filename' => 'test.mp4',
            ],
        ],
    ]);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response([
            'id' => 'container-123',
        ], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response([
            'status' => 'FINISHED',
        ], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response([
            'id' => 'video-123',
        ], 200),
        'https://graph.threads.net/v1.0/video-123*' => Http::response([
            'permalink' => 'https://threads.net/@testuser/post/456',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->post);

    expect($result['id'])->toBe('video-123');
});

test('threads publisher keeps links intact', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    $this->post->update(['content' => 'New post: https://acme.com/blog']);

    Http::fake([
        'https://graph.threads.net/v1.0/123456789/threads' => Http::response(['id' => 'container-123'], 200),
        'https://graph.threads.net/v1.0/container-123*' => Http::response(['status' => 'FINISHED'], 200),
        'https://graph.threads.net/v1.0/123456789/threads_publish' => Http::response(['id' => 'post-123456789'], 200),
        'https://graph.threads.net/v1.0/post-123456789*' => Http::response([
            'permalink' => 'https://www.threads.net/@testuser/post/ABC123',
        ], 200),
    ]);

    $this->publisher->publish($this->post);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/123456789/threads')
        && ! str_contains($request->url(), 'threads_publish')
        && data_get($request->data(), 'text') === 'New post: https://acme.com/blog');
});

function fakeThreadsContainerFlow(): void
{
    $base = config('trypost.platforms.threads.graph_api');

    Http::fake([
        "{$base}/123456789/threads" => Http::response(['id' => 'container-1']),
        "{$base}/123456789/threads_publish" => Http::response(['id' => 'post-1']),
        "{$base}/post-1*" => Http::response(['permalink' => 'https://www.threads.net/@testuser/post/A']),
        "{$base}/container-1*" => Http::response(['status' => 'FINISHED']),
    ]);
}

test('a ghost post is a text container flagged as ghost and nothing else', function () {
    $this->post->update([
        'content_type' => ContentType::ThreadsGhostPost,
        'meta' => ['topic_tag' => 'laravel'],
    ]);
    fakeThreadsContainerFlow();

    $this->publisher->publish($this->post->fresh());

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/123456789/threads')
        && data_get($request->data(), 'media_type') === 'TEXT'
        && data_get($request->data(), 'is_ghost_post') === 'true'
        && ! array_key_exists('topic_tag', $request->data()));
});

test('a ghost post with media fails before calling threads', function () {
    $this->post->update(['content_type' => ContentType::ThreadsGhostPost]);
    $this->post->update(['media' => [['id' => 'm', 'type' => 'image', 'path' => 'm.jpg', 'url' => 'https://example.com/m.jpg', 'mime_type' => 'image/jpeg']]]);
    Http::fake();

    expect(fn () => $this->publisher->publish($this->post->fresh()))->toThrow(ThreadsPublishException::class);
    Http::assertNothingSent();
});

test('a ghost post without text fails before calling threads', function () {
    $this->post->update(['content_type' => ContentType::ThreadsGhostPost]);
    $this->post->update(['content' => null]);
    Http::fake();

    expect(fn () => $this->publisher->publish($this->post->fresh()))->toThrow(ThreadsPublishException::class);
    Http::assertNothingSent();
});

test('the threads topic tag reaches the text container without its hash', function () {
    $this->post->update(['meta' => ['topic_tag' => '#laravel']]);
    fakeThreadsContainerFlow();

    $this->publisher->publish($this->post->fresh());

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/123456789/threads')
        && data_get($request->data(), 'topic_tag') === 'laravel'
        && ! array_key_exists('is_ghost_post', $request->data()));
});

test('the threads topic tag reaches an image container', function () {
    $this->post->update(['media' => [['id' => 'm', 'type' => 'image', 'path' => 'm.jpg', 'url' => 'https://example.com/m.jpg', 'mime_type' => 'image/jpeg']]]);
    $this->post->update(['meta' => ['topic_tag' => 'laravel']]);
    fakeThreadsContainerFlow();

    $this->publisher->publish($this->post->fresh());

    Http::assertSent(fn ($request): bool => data_get($request->data(), 'media_type') === 'IMAGE'
        && data_get($request->data(), 'topic_tag') === 'laravel');
});

test('the threads topic tag reaches the carousel container and never its children', function () {
    $this->post->update(['media' => [
        ['id' => 'a', 'type' => 'image', 'path' => 'a.jpg', 'url' => 'https://example.com/a.jpg', 'mime_type' => 'image/jpeg'],
        ['id' => 'b', 'type' => 'image', 'path' => 'b.jpg', 'url' => 'https://example.com/b.jpg', 'mime_type' => 'image/jpeg'],
    ]]);
    $this->post->update(['meta' => ['topic_tag' => 'laravel']]);
    fakeThreadsContainerFlow();

    $this->publisher->publish($this->post->fresh());

    Http::assertSent(fn ($request): bool => data_get($request->data(), 'media_type') === 'CAROUSEL'
        && data_get($request->data(), 'topic_tag') === 'laravel');
    Http::assertNotSent(fn ($request): bool => data_get($request->data(), 'is_carousel_item') === 'true'
        && array_key_exists('topic_tag', $request->data()));
});

test('a threads post without a topic sends only text', function () {
    fakeThreadsContainerFlow();

    $this->publisher->publish($this->post);

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/123456789/threads')
        && array_keys($request->data()) === ['media_type', 'text', 'access_token']);
});

test('a ghost post with a link is published as a ghost text container', function () {
    $this->post->update(['content_type' => ContentType::ThreadsGhostPost]);
    $this->post->update(['content' => 'Read https://example.com/article']);
    fakeThreadsContainerFlow();

    $this->publisher->publish($this->post->fresh());

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/123456789/threads')
        && data_get($request->data(), 'media_type') === 'TEXT'
        && data_get($request->data(), 'is_ghost_post') === 'true'
        && str_contains((string) data_get($request->data(), 'text'), 'https://example.com/article'));
});

test('a text post waits for its container before publishing and recreates it when threads cannot find it', function () {
    $this->post->update(['content_type' => ContentType::ThreadsGhostPost]);
    $base = config('trypost.platforms.threads.graph_api');
    $containerCreations = 0;
    $publicationAttempts = 0;
    $requestOrder = [];

    Http::fake(function ($request) use ($base, &$containerCreations, &$publicationAttempts, &$requestOrder) {
        $url = $request->url();

        if (str_starts_with($url, "{$base}/123456789/threads_publish")) {
            $publicationAttempts++;
            $requestOrder[] = 'publish';

            return $publicationAttempts === 1
                ? Http::response(['error' => ['code' => 24, 'error_subcode' => 4279009, 'message' => 'The requested resource does not exist']], 400)
                : Http::response(['id' => 'post-1']);
        }

        if (str_starts_with($url, "{$base}/123456789/threads")) {
            $containerCreations++;
            $requestOrder[] = 'create';

            return Http::response(['id' => "container-{$containerCreations}"]);
        }

        if (str_starts_with($url, "{$base}/container-")) {
            $requestOrder[] = 'status';

            return Http::response(['status' => 'FINISHED']);
        }

        return Http::response(['permalink' => 'https://www.threads.net/@testuser/post/A']);
    });

    $result = $this->publisher->publish($this->post->fresh());

    expect($result['id'])->toBe('post-1')
        ->and($containerCreations)->toBe(2)
        ->and($publicationAttempts)->toBe(2)
        ->and(array_slice($requestOrder, 0, 3))->toBe(['create', 'status', 'publish']);
});

test('a threads poll is a text container carrying its choices as option_a to option_d', function () {
    $this->post->update([
        'content' => 'Tabs or spaces?',
        'content_type' => ContentType::ThreadsPoll,
        'meta' => ['poll' => ['options' => ['Tabs', 'Spaces', 'Both']], 'topic_tag' => 'laravel'],
    ]);
    fakeThreadsContainerFlow();

    $this->publisher->publish($this->post->fresh());

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/123456789/threads')
        && data_get($request->data(), 'media_type') === 'TEXT'
        && data_get($request->data(), 'text') === 'Tabs or spaces?'
        && data_get($request->data(), 'topic_tag') === 'laravel'
        && json_decode((string) data_get($request->data(), 'poll_attachment'), true) === ['option_a' => 'Tabs', 'option_b' => 'Spaces', 'option_c' => 'Both']);
});

test('a threads poll that cannot go fails before calling threads', function (array $changes, string $message) {
    $this->post->update([
        'content' => 'Tabs or spaces?',
        'content_type' => ContentType::ThreadsPoll,
        'meta' => ['poll' => ['options' => ['Tabs', 'Spaces']]],
        ...$changes,
    ]);
    Http::fake();

    expect(fn () => $this->publisher->publish($this->post->fresh()))->toThrow(ThreadsPublishException::class, __($message, ['min' => 2]));
    Http::assertNothingSent();
})->with([
    'media' => [['media' => [['id' => 'm', 'type' => 'image', 'path' => 'm.jpg', 'url' => 'https://example.com/m.jpg', 'mime_type' => 'image/jpeg']]], 'posts.errors.media_not_accepted'],
    'one choice' => [['meta' => ['poll' => ['options' => ['Tabs']]]], 'posts.form.poll.options_required'],
    'repeated choices' => [['meta' => ['poll' => ['options' => ['Tabs', ' tabs']]]], 'posts.form.poll.options_distinct'],
    'no question' => [['content' => ''], 'posts.form.poll.question_required'],
]);

test('a threads poll with four choices sends option_a to option_d', function () {
    $this->post->update([
        'content' => 'Pick a color',
        'content_type' => ContentType::ThreadsPoll,
        'meta' => ['poll' => ['options' => ['Red', 'Green', 'Blue', 'Gold']]],
    ]);
    fakeThreadsContainerFlow();

    $this->publisher->publish($this->post->fresh());

    Http::assertSent(fn ($request): bool => json_decode((string) data_get($request->data(), 'poll_attachment'), true) === [
        'option_a' => 'Red',
        'option_b' => 'Green',
        'option_c' => 'Blue',
        'option_d' => 'Gold',
    ]);
});

/**
 * Fakes a thread on Threads: each container creation answers the next of
 * `$containers` (three ready containers by default), and each publication
 * the next post id, from post-1.
 */
function fakeThreadsThreadFlow(PromiseInterface ...$containers): void
{
    $base = config('trypost.platforms.threads.graph_api');

    if (blank($containers)) {
        $containers = [
            Http::response(['id' => 'container-1']),
            Http::response(['id' => 'container-2']),
            Http::response(['id' => 'container-3']),
        ];
    }

    Http::fake([
        "{$base}/123456789/threads" => Http::sequence($containers),
        "{$base}/123456789/threads_publish" => Http::sequence()
            ->push(['id' => 'post-1'])
            ->push(['id' => 'post-2'])
            ->push(['id' => 'post-3']),
        "{$base}/container-*" => Http::response(['status' => 'FINISHED']),
        "{$base}/post-*" => Http::response(['permalink' => 'https://www.threads.net/@testuser/post/A']),
    ]);
}

/**
 * The fields of every container sent to Threads, in order.
 *
 * @return Collection<int, array<string, mixed>>
 */
function threadsContainersSent(): Collection
{
    return Http::recorded(fn ($request): bool => Str::endsWith($request->url(), '/123456789/threads'))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->values();
}

/**
 * @return array<string, string>
 */
function threadsImage(string $id): array
{
    return ['id' => $id, 'type' => 'image', 'path' => "{$id}.jpg", 'url' => "https://example.com/{$id}.jpg", 'mime_type' => 'image/jpeg'];
}

/**
 * An account connected before TryPost asked for `threads_manage_replies`.
 */
function connectedBeforeRepliesPermission(SocialAccount $account): void
{
    $account->update(['scopes' => ['threads_basic', 'threads_content_publish', 'threads_manage_insights']]);
}

test('a threads thread publishes each reply under the post before it, with the topic on the first post only', function () {
    $this->post->update([
        'content' => 'First',
        'meta' => ['topic_tag' => 'laravel', 'thread_replies' => ['Second', ['text' => 'Third', 'media' => []]]],
    ]);
    fakeThreadsThreadFlow();

    $result = $this->publisher->publish($this->post->fresh());

    expect($result)->toBe([
        'id' => 'post-1',
        'url' => 'https://www.threads.net/@testuser/post/A',
        'thread_reply_ids' => ['post-2', 'post-3'],
    ])
        ->and(threadsContainersSent()->pluck('text')->all())->toBe(['First', 'Second', 'Third'])
        ->and(threadsContainersSent()->pluck('reply_to_id')->all())->toBe([null, 'post-1', 'post-2'])
        ->and(threadsContainersSent()->pluck('topic_tag')->all())->toBe(['laravel', null, null]);
});

test('a threads reply carries its own single media item', function (array $media, array $expected) {
    $this->post->update([
        'content' => 'First',
        'meta' => ['thread_replies' => [['text' => 'Look', 'media' => [$media]]]],
    ]);
    fakeThreadsThreadFlow();

    $this->publisher->publish($this->post->fresh());

    expect(threadsContainersSent()->last())->toMatchArray([...$expected, 'reply_to_id' => 'post-1']);
})->with([
    'image' => [threadsImage('m'), ['media_type' => 'IMAGE', 'image_url' => 'https://example.com/m.jpg']],
    'video' => [
        ['id' => 'm', 'type' => 'video', 'path' => 'm.mp4', 'url' => 'https://example.com/m.mp4', 'mime_type' => 'video/mp4'],
        ['media_type' => 'VIDEO', 'video_url' => 'https://example.com/m.mp4'],
    ],
]);

test('a threads reply with several images is a carousel under the post before it', function () {
    $this->post->update([
        'content' => 'First',
        'meta' => ['thread_replies' => [['text' => 'Look', 'media' => [threadsImage('a'), threadsImage('b')]]]],
    ]);
    fakeThreadsThreadFlow(
        Http::response(['id' => 'container-1']),
        Http::response(['id' => 'container-child-a']),
        Http::response(['id' => 'container-child-b']),
        Http::response(['id' => 'container-carousel']),
    );

    $result = $this->publisher->publish($this->post->fresh());

    expect($result['thread_reply_ids'])->toBe(['post-2'])
        ->and(threadsContainersSent()->pluck('is_carousel_item')->all())->toBe([null, 'true', 'true', null])
        ->and(threadsContainersSent()->last())->toMatchArray([
            'media_type' => 'CAROUSEL',
            'children' => 'container-child-a,container-child-b',
            'reply_to_id' => 'post-1',
        ]);
});

test('a threads carousel reply is not published with missing media when one item fails', function (array $body, int $status) {
    $this->post->update([
        'content' => 'First',
        'meta' => ['thread_replies' => [['text' => 'Look', 'media' => [threadsImage('a'), threadsImage('b'), threadsImage('c')]]]],
    ]);
    fakeThreadsThreadFlow(
        Http::response(['id' => 'container-1']),
        Http::response(['id' => 'container-child-a']),
        Http::response($body, $status),
        Http::response(['id' => 'container-child-c']),
        Http::response(['id' => 'container-carousel']),
    );

    expect(fn () => $this->publisher->publish($this->post->fresh()))->toThrow(ThreadsPublishException::class);

    $published = Http::recorded(fn ($request): bool => Str::endsWith($request->url(), '/threads_publish'));

    expect($published)->toHaveCount(1)
        ->and(data_get($this->post->fresh()->error_context, ThreadProgress::KEY.'.*.id'))->toBe(['post-1'])
        ->and(threadsContainersSent())->toHaveCount(3);
})->with([
    'rejected item' => [['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400],
    'missing container id' => [[], 200],
]);

test('a threads reply poll is a text reply carrying its choices', function () {
    $this->post->update([
        'content' => 'First',
        'meta' => ['thread_replies' => [['text' => 'Tabs or spaces?', 'media' => [], 'poll' => ['options' => ['Tabs', 'Spaces']]]]],
    ]);
    fakeThreadsThreadFlow();

    $this->publisher->publish($this->post->fresh());

    $reply = threadsContainersSent()->last();

    expect($reply)->toMatchArray(['media_type' => 'TEXT', 'reply_to_id' => 'post-1'])
        ->and(json_decode((string) data_get($reply, 'poll_attachment'), true))->toBe(['option_a' => 'Tabs', 'option_b' => 'Spaces']);
});

test('a threads thread resumes after the posts already live', function () {
    $this->post->update([
        'content' => 'First',
        'meta' => ['thread_replies' => ['Second', 'Third']],
    ]);
    ThreadProgress::remember($this->post, [
        ['hash' => ThreadProgress::hash('First'), 'id' => 'previous-root', 'url' => 'https://www.threads.net/@testuser/post/A'],
        ['hash' => ThreadProgress::hash('Second'), 'id' => 'previous-reply'],
    ]);
    fakeThreadsThreadFlow();

    $result = $this->publisher->publish($this->post->fresh());

    expect(threadsContainersSent())->toHaveCount(1)
        ->and(threadsContainersSent()->first())->toMatchArray(['text' => 'Third', 'reply_to_id' => 'previous-reply'])
        ->and($result['id'])->toBe('previous-root')
        ->and($result['thread_reply_ids'])->toBe(['previous-reply', 'post-1']);
});

test('a threads reply refused by threads keeps the live post checkpointed for the retry', function () {
    $this->post->update([
        'content' => 'First',
        'meta' => ['thread_replies' => ['Second']],
    ]);
    fakeThreadsThreadFlow(
        Http::response(['id' => 'container-1']),
        Http::response(['error' => ['message' => 'Invalid parameter', 'code' => 100]], 400),
    );

    expect(fn () => $this->publisher->publish($this->post->fresh()))->toThrow(ThreadsPublishException::class);

    $progress = data_get($this->post->fresh()->error_context, ThreadProgress::KEY);

    expect($progress)->toHaveCount(1)
        ->and(data_get($progress, '0.id'))->toBe('post-1');
});

test('a threads thread on an account without the replies permission fails before calling threads', function () {
    connectedBeforeRepliesPermission($this->socialAccount);
    $this->post->update(['meta' => ['thread_replies' => ['Second']]]);
    Http::fake();

    expect(fn () => $this->publisher->publish($this->post->fresh()))
        ->toThrow(MissingPermissionException::class, __('channels.permissions.thread_replies.message', ['network' => Platform::Threads->label()]));
    Http::assertNothingSent();
});

test('a single threads post on an account without the replies permission still publishes', function () {
    connectedBeforeRepliesPermission($this->socialAccount);
    fakeThreadsContainerFlow();

    expect($this->publisher->publish($this->post->fresh())['id'])->toBe('post-1');
});

test('omitting the replies scope from the install config blocks thread replies but allows single posts', function () {
    config(['trypost.platforms.threads.scopes' => ['threads_basic', 'threads_content_publish', 'threads_manage_insights']]);
    $this->post->update(['meta' => ['thread_replies' => ['Second']]]);
    fakeThreadsContainerFlow();

    expect(fn () => $this->publisher->publish($this->post->fresh()))
        ->toThrow(ContentLimitException::class, __('posts.form.thread.unsupported'));
    Http::assertNothingSent();

    $this->post->update(['meta' => []]);

    expect($this->publisher->publish($this->post->fresh())['id'])->toBe('post-1');
});

test('a threads ghost post with replies fails before calling threads', function () {
    $this->post->update([
        'content_type' => ContentType::ThreadsGhostPost,
        'meta' => ['thread_replies' => ['Second']],
    ]);
    Http::fake();

    expect(fn () => $this->publisher->publish($this->post->fresh()))
        ->toThrow(ContentLimitException::class, __('posts.form.thread.ghost_post'));
    Http::assertNothingSent();
});
