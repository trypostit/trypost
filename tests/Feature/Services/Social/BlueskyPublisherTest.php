<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\LinkCard\LinkCardFetcher;
use App\Services\Social\LinkCard\LinkCardMetadata;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->bluesky()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'did:plc:testuser123',
        'username' => 'testuser.bsky.social',
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from Bluesky!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Bluesky,
        'content_type' => ContentType::BlueskyPost,
    ]);

    $this->publisher = new BlueskyPublisher;

    // Default: no link card. Card-specific tests override this mock.
    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->andReturn(null)
        ->byDefault();
});

test('bluesky publisher can publish text-only post', function () {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result)->toHaveKey('url');
    expect($result['id'])->toBe('3abc123xyz');
    expect($result['url'])->toContain('bsky.app/profile/testuser.bsky.social/post/3abc123xyz');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'createRecord')
            && $request['record']['text'] === 'Hello from Bluesky!';
    });
});

test('bluesky publisher parses URLs as facets', function () {
    $this->post->update(['content' => 'Check out https://example.com for more info!']);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $record = $request['record'];

        return isset($record['facets'])
            && count($record['facets']) > 0
            && $record['facets'][0]['features'][0]['$type'] === 'app.bsky.richtext.facet#link';
    });
});

test('bluesky publisher parses hashtags as facets', function () {
    $this->post->update(['content' => 'Hello #bluesky #test']);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $record = $request['record'];

        return isset($record['facets']) && count($record['facets']) >= 2;
    });
});

test('bluesky publisher strips trailing punctuation from URL facets', function () {
    $this->post->update(['content' => 'see https://example.com).']);

    Http::fake([
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $link = collect($request['record']['facets'] ?? [])
            ->first(fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#link');

        return $link
            && $link['features'][0]['uri'] === 'https://example.com'
            && $link['index']['byteEnd'] === $link['index']['byteStart'] + strlen('https://example.com');
    });
});

test('bluesky publisher keeps a closing paren that has a matching open paren', function () {
    $this->post->update(['content' => 'see https://en.wikipedia.org/wiki/Foo_(bar)']);

    Http::fake([
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $link = collect($request['record']['facets'] ?? [])
            ->first(fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#link');

        // The trailing ')' is part of the URL because it has a matching '('.
        return $link && $link['features'][0]['uri'] === 'https://en.wikipedia.org/wiki/Foo_(bar)';
    });
});

test('bluesky publisher computes byte offsets after multibyte characters', function () {
    $this->post->update(['content' => 'Olá 🎉 #café']);

    Http::fake([
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $text = $request['record']['text'];
        $tag = collect($request['record']['facets'] ?? [])
            ->first(fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#tag');

        // byteStart must be the UTF-8 byte position of '#café', not its character index.
        return $tag
            && $tag['index']['byteStart'] === strpos($text, '#café')
            && $tag['index']['byteEnd'] === strpos($text, '#café') + strlen('#café');
    });
});

test('bluesky publisher resolves mentions to DIDs as facets', function () {
    $this->post->update(['content' => 'Shout out to @friend.bsky.social']);

    Http::fake([
        // Wildcard so the fake matches whichever endpoint resolveHandleToDid()
        // tries first (the account PDS, public AppView, or bsky.social) and the
        // test stays isolated from the configured service URL.
        '*/xrpc/com.atproto.identity.resolveHandle*' => Http::response([
            'did' => 'did:plc:friend456',
        ], 200),
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $record = $request->data()['record'] ?? null;

        if (! $record || ! isset($record['facets'])) {
            return false;
        }

        foreach ($record['facets'] as $facet) {
            $feature = $facet['features'][0];
            if ($feature['$type'] === 'app.bsky.richtext.facet#mention') {
                // The facet must carry the resolved DID, not the raw handle.
                return $feature['did'] === 'did:plc:friend456';
            }
        }

        return false;
    });
});

test('bluesky publisher skips mention facet when handle cannot be resolved', function () {
    $this->post->update(['content' => 'Shout out to @ghost.bsky.social']);

    Http::fake([
        '*/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['error' => 'InvalidRequest'], 400),
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    // Post still publishes; the unresolved @handle stays as plain text.
    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('3abc123xyz');

    Http::assertSent(function ($request) {
        $record = $request->data()['record'] ?? null;

        if (! $record) {
            return false;
        }

        $hasMentionFacet = collect($record['facets'] ?? [])->contains(
            fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#mention'
        );

        return str_contains($record['text'], '@ghost.bsky.social') && ! $hasMentionFacet;
    });
});

test('bluesky publisher publishes as plain text when handle resolution errors', function () {
    $this->post->update(['content' => 'hi @friend.bsky.social']);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'resolveHandle')) {
            throw new ConnectionException('connection refused');
        }

        return Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200);
    });

    // A network error resolving the handle must degrade to plain text, not fail the post.
    $result = $this->publisher->publish($this->postPlatform);

    expect($result['id'])->toBe('3abc123xyz');

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $hasMention = collect($request['record']['facets'] ?? [])
            ->contains(fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#mention');

        return str_contains($request['record']['text'], '@friend.bsky.social') && ! $hasMention;
    });
});

test('bluesky publisher builds the post url from the configured web app host', function () {
    config(['trypost.platforms.bluesky.web_app' => 'https://custom.bsky.example']);

    Http::fake([
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform);

    expect($result['url'])->toBe('https://custom.bsky.example/profile/testuser.bsky.social/post/3abc123xyz');
});

test('bluesky publisher resolves some mentions and skips the unresolvable ones', function () {
    $this->post->update(['content' => 'cc @good.bsky.social and @bad.bsky.social']);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'resolveHandle')) {
            // `good` resolves; `bad` answers 200 without a DID — treated as
            // unresolvable, exercising the str_starts_with('did:') guard.
            return str_contains($request->url(), 'good.bsky.social')
                ? Http::response(['did' => 'did:plc:good999'], 200)
                : Http::response([], 200);
        }

        return Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        $record = $request->data()['record'] ?? null;

        if (! $record) {
            return false;
        }

        $mentions = collect($record['facets'] ?? [])
            ->filter(fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#mention');

        // Only the resolvable handle becomes a facet; the other stays plain text.
        return $mentions->count() === 1
            && $mentions->first()['features'][0]['did'] === 'did:plc:good999'
            && str_contains($record['text'], '@bad.bsky.social');
    });
});

test('bluesky publisher resolves a repeated handle only once', function () {
    $this->post->update(['content' => 'thanks @dup.bsky.social, really @dup.bsky.social']);

    Http::fake([
        '*/xrpc/com.atproto.identity.resolveHandle*' => Http::response(['did' => 'did:plc:dup789'], 200),
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    // The same handle appears twice but the per-post cache resolves it once.
    $resolveCalls = Http::recorded(fn ($request) => str_contains($request->url(), 'resolveHandle'))->count();
    expect($resolveCalls)->toBe(1);

    // Both occurrences still become mention facets carrying the DID.
    Http::assertSent(function ($request) {
        $record = $request->data()['record'] ?? null;

        if (! $record) {
            return false;
        }

        $mentions = collect($record['facets'] ?? [])
            ->filter(fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#mention');

        return $mentions->count() === 2
            && $mentions->every(fn ($facet) => $facet['features'][0]['did'] === 'did:plc:dup789');
    });
});

test('bluesky publisher attaches an uploaded image as an embed', function () {
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

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'bsky_test_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'uploadBlob')) {
            return Http::response([
                'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreiabc123'], 'mimeType' => 'image/jpeg', 'size' => 1024],
            ], 200);
        }

        if (str_contains($request->url(), 'createRecord')) {
            return Http::response([
                'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
                'cid' => 'bafyreiabc123',
            ], 200);
        }

        return Http::response(str_repeat('x', 1024), 200); // media download
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.images'
            && count($embed['images']) === 1
            && data_get($embed, 'images.0.image.ref.$link') === 'bafkreiabc123';
    });
});

test('bluesky publisher includes image alt text in the embed', function () {
    $this->post->update([
        'media' => [
            [
                'id' => 'test-media-id',
                'path' => 'media/2026-01/test-image.jpg',
                'url' => 'https://example.com/media/2026-01/test-image.jpg',
                'mime_type' => 'image/jpeg',
                'original_filename' => 'test.jpg',
                'meta' => ['alt_text' => 'a red bike'],
            ],
        ],
    ]);

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'bsky_test_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'uploadBlob')) {
            return Http::response([
                'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreiabc123'], 'mimeType' => 'image/jpeg', 'size' => 1024],
            ], 200);
        }

        if (str_contains($request->url(), 'createRecord')) {
            return Http::response([
                'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
                'cid' => 'bafyreiabc123',
            ], 200);
        }

        return Http::response(str_repeat('x', 1024), 200); // media download
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        return data_get($request->data(), 'record.embed.images.0.alt') === 'a red bike';
    });
});

test('bluesky publisher sends empty-string alt when none is set', function () {
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

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'bsky_test_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'uploadBlob')) {
            return Http::response([
                'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreiabc123'], 'mimeType' => 'image/jpeg', 'size' => 1024],
            ], 200);
        }

        if (str_contains($request->url(), 'createRecord')) {
            return Http::response([
                'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
                'cid' => 'bafyreiabc123',
            ], 200);
        }

        return Http::response(str_repeat('x', 1024), 200); // media download
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        return data_get($request->data(), 'record.embed.images.0.alt') === '';
    });
});

test('bluesky publisher refreshes token when expired', function () {
    $this->socialAccount->update(['token_expires_at' => now()->subHour()]);

    Http::fake([
        'https://bsky.social/xrpc/com.atproto.server.refreshSession' => Http::response([
            'did' => 'did:plc:testuser123',
            'handle' => 'testuser.bsky.social',
            'accessJwt' => 'new-access-token',
            'refreshJwt' => 'new-refresh-token',
        ], 200),
        'https://bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'refreshSession');
    });

    $this->socialAccount->refresh();
    expect($this->socialAccount->access_token)->toBe('new-access-token');
});

test('bluesky publisher throws exception on api error', function () {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
            'error' => 'InvalidRequest',
            'message' => 'Something went wrong',
        ], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(Exception::class);
});

test('bluesky publisher throws token expired exception on auth error', function () {
    Http::fake([
        'https://bsky.social/xrpc/com.atproto.repo.createRecord' => Http::response([
            'error' => 'ExpiredToken',
            'message' => 'Token has expired',
        ], 401),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform))
        ->toThrow(TokenExpiredException::class);
});

test('bluesky publisher optimizes images before upload', function () {
    $tempFile = tempnam(sys_get_temp_dir(), 'bsky_test_');
    file_put_contents($tempFile, str_repeat('x', 1024));

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

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->once()
        ->with(Mockery::any(), Platform::Bluesky)
        ->andReturn($tempFile);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'uploadBlob')) {
            return Http::response([
                'blob' => [
                    '$type' => 'blob',
                    'ref' => ['$link' => 'bafkreiabc123'],
                    'mimeType' => 'image/jpeg',
                    'size' => 1024,
                ],
            ], 200);
        }

        if (str_contains($url, 'createRecord')) {
            return Http::response([
                'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
                'cid' => 'bafyreiabc123',
            ], 200);
        }

        // Media download fallback (covers relative storage URLs in test env)
        return Http::response(str_repeat('x', 1024), 200);
    });

    $this->publisher->publish($this->postPlatform);

    @unlink($tempFile);
});

test('bluesky publisher handles media download failure gracefully', function () {
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

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'createRecord')) {
            return Http::response([
                'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3textonly',
                'cid' => 'bafyreiabc123',
            ], 200);
        }

        // CDN download returns 404 — blob upload is skipped
        return Http::response('Not Found', 404);
    });

    // When media download fails, uploadBlob returns null and post publishes as text-only
    $result = $this->publisher->publish($this->postPlatform);

    expect($result)->toHaveKey('id');
    expect($result['id'])->toBe('3textonly');

    // The createRecord request should NOT contain an embed (no images uploaded)
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }
        $record = $request['record'];

        return ! isset($record['embed']);
    });
});

test('bluesky publisher limits images to 4', function () {
    $mediaItems = [];
    for ($i = 0; $i < 6; $i++) {
        $mediaItems[] = [
            'id' => "test-media-{$i}",
            'path' => "media/2026-01/test-image-{$i}.jpg",
            'url' => "https://example.com/media/2026-01/test-image-{$i}.jpg",
            'mime_type' => 'image/jpeg',
            'original_filename' => "test-{$i}.jpg",
        ];
    }
    $this->post->update(['media' => $mediaItems]);

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'bsky_test_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'uploadBlob')) {
            return Http::response([
                'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafkreiabc123'], 'mimeType' => 'image/jpeg', 'size' => 1024],
            ], 200);
        }

        if (str_contains($request->url(), 'createRecord')) {
            return Http::response([
                'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
                'cid' => 'bafyreiabc123',
            ], 200);
        }

        return Http::response(str_repeat('x', 1024), 200); // media download
    });

    $this->publisher->publish($this->postPlatform);

    // Six images provided, but Bluesky caps at 4: only 4 blobs upload and the embed carries 4.
    $uploads = Http::recorded(fn ($request) => str_contains($request->url(), 'uploadBlob'))->count();
    expect($uploads)->toBe(4);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'createRecord')
            && count($request['record']['embed']['images'] ?? []) === 4;
    });
});

/**
 * Attach a single video to the post under test (mp4 by default).
 */
function attachBlueskyVideo(Post $post, string $mimeType = 'video/mp4'): void
{
    $post->update([
        'media' => [[
            'id' => 'test-video-id',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'mime_type' => $mimeType,
            'original_filename' => 'test.mp4',
        ]],
    ]);
}

/**
 * Fake the Bluesky video pipeline. Order matters: getServiceAuth must be
 * matched before uploadBlob because its query carries lxm=...uploadBlob.
 */
function fakeBlueskyVideoPipeline(string $jobState = 'JOB_STATE_COMPLETED', bool $blobOnComplete = true): void
{
    // Poll without sleeping so multi-poll paths stay instant under test.
    config(['trypost.platforms.bluesky.video_poll_seconds' => 0]);

    Http::fake(function ($request) use ($jobState, $blobOnComplete) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response([
                'service' => [[
                    'id' => '#atproto_pds',
                    'type' => 'AtprotoPersonalDataServer',
                    'serviceEndpoint' => 'https://pds.example.host',
                ]],
            ], 200);
        }

        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }

        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            // uploadVideo returns the jobStatus object directly (not wrapped).
            return Http::response(['jobId' => 'job-123', 'did' => 'did:plc:testuser123', 'state' => 'JOB_STATE_CREATED'], 200);
        }

        if (str_contains($url, 'app.bsky.video.getJobStatus')) {
            $status = ['jobId' => 'job-123', 'state' => $jobState];
            if ($jobState === 'JOB_STATE_COMPLETED' && $blobOnComplete) {
                $status['blob'] = ['$type' => 'blob', 'ref' => ['$link' => 'bafvideo123'], 'mimeType' => 'video/mp4', 'size' => 2048];
            }
            if ($jobState === 'JOB_STATE_FAILED') {
                $status['message'] = 'processing error';
            }

            return Http::response(['jobStatus' => $status], 200);
        }

        if (str_contains($url, 'createRecord')) {
            return Http::response([
                'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3vid123xyz',
                'cid' => 'bafyreivid123',
            ], 200);
        }

        return Http::response(str_repeat('v', 2048), 200); // video download
    });
}

test('bluesky publisher uploads a video and embeds it', function () {
    attachBlueskyVideo($this->post);

    fakeBlueskyVideoPipeline();

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.video'
            && data_get($embed, 'video.ref.$link') === 'bafvideo123';
    });

    // The video goes to the video service, never to the PDS uploadBlob endpoint.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'app.bsky.video.uploadVideo'));
});

test('bluesky publisher scopes the upload service-auth to the resolved PDS host', function () {
    attachBlueskyVideo($this->post);

    fakeBlueskyVideoPipeline();

    $this->publisher->publish($this->postPlatform);

    // Audience for the upload token must be the real PDS host from the DID doc,
    // not the bsky.social entryway the account was connected through.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'getServiceAuth')
            && str_contains($request->url(), 'aud=did%3Aweb%3Apds.example.host')
            && str_contains($request->url(), 'lxm=com.atproto.repo.uploadBlob');
    });
});

test('bluesky publisher publishes text-only when video processing fails', function () {
    $this->post->update([
        'media' => [[
            'id' => 'test-video-id',
            'path' => 'media/2026-01/test-video.mp4',
            'url' => 'https://example.com/media/2026-01/test-video.mp4',
            'mime_type' => 'video/mp4',
            'original_filename' => 'test.mp4',
        ]],
    ]);

    fakeBlueskyVideoPipeline('JOB_STATE_FAILED');

    $this->publisher->publish($this->postPlatform);

    // A failed transcode must not crash the job; the post still goes out as text.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'createRecord')
            && ! isset($request['record']['embed']);
    });
});

test('bluesky publisher retries a transient video transcode failure', function () {
    attachBlueskyVideo($this->post);
    config(['trypost.platforms.bluesky.video_poll_seconds' => 0]);

    $jobCalls = 0;
    Http::fake(function ($request) use (&$jobCalls) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [[
                'id' => '#atproto_pds',
                'type' => 'AtprotoPersonalDataServer',
                'serviceEndpoint' => 'https://pds.example.host',
            ]]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            return Http::response(['jobId' => 'job-123', 'state' => 'JOB_STATE_CREATED'], 200);
        }
        if (str_contains($url, 'app.bsky.video.getJobStatus')) {
            $jobCalls++;
            // First attempt's job fails transiently; the retry succeeds.
            if ($jobCalls === 1) {
                return Http::response(['jobStatus' => ['jobId' => 'job-123', 'state' => 'JOB_STATE_FAILED', 'message' => 'transient']], 200);
            }

            return Http::response(['jobStatus' => [
                'jobId' => 'job-123', 'state' => 'JOB_STATE_COMPLETED',
                'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafretry456'], 'mimeType' => 'video/mp4', 'size' => 2048],
            ]], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3retry', 'cid' => 'bafretry'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    expect($jobCalls)->toBeGreaterThanOrEqual(2);
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }
        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.video'
            && data_get($embed, 'video.ref.$link') === 'bafretry456';
    });
});

test('bluesky publisher skips an oversized video and publishes text-only', function () {
    attachBlueskyVideo($this->post);
    config(['trypost.platforms.bluesky.video_max_bytes' => 1024]);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3big', 'cid' => 'bafbig'], 200);
        }

        // Video download is 2 KB — over the 1 KB cap set above.
        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // Oversized video is dropped before any upload; the post still goes out as text.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['embed']));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'app.bsky.video.uploadVideo'));
});

test('bluesky publisher publishes text-only when the video download fails', function () {
    attachBlueskyVideo($this->post);

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3dl', 'cid' => 'bafdl'], 200);
        }

        // The CDN download 404s — no video, no service-auth, just text.
        return Http::response('Not Found', 404);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['embed']));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'getServiceAuth'));
});

test('bluesky publisher publishes text-only when service-auth minting fails', function () {
    attachBlueskyVideo($this->post);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['error' => 'AuthRequired'], 400);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3auth', 'cid' => 'bafauth'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // Without a service-auth token the upload can't proceed; degrade to text.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['embed']));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'app.bsky.video.uploadVideo'));
});

test('bluesky publisher embeds the existing blob when upload returns 409 already-exists', function () {
    attachBlueskyVideo($this->post);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            // Re-upload of identical bytes: 409 carrying the already-finished job.
            return Http::response([
                'jobId' => 'job-dup', 'state' => 'JOB_STATE_COMPLETED',
                'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafdup789'], 'mimeType' => 'video/mp4', 'size' => 2048],
            ], 409);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3dup', 'cid' => 'bafdup'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }
        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.video'
            && data_get($embed, 'video.ref.$link') === 'bafdup789';
    });

    // The blob came straight from the 409 body; no job polling needed. Match the
    // endpoint path, not the bare NSID — the status-token getServiceAuth request
    // also carries `lxm=app.bsky.video.getJobStatus` in its query string.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'xrpc/app.bsky.video.getJobStatus'));
});

test('bluesky publisher publishes text-only when upload returns no job id', function () {
    attachBlueskyVideo($this->post);
    config(['trypost.platforms.bluesky.video_poll_seconds' => 0]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            return Http::response([], 200); // neither blob nor jobId
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3nojob', 'cid' => 'bafnojob'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['embed']));
});

test('bluesky publisher publishes text-only when getJobStatus errors', function () {
    attachBlueskyVideo($this->post);
    config(['trypost.platforms.bluesky.video_poll_seconds' => 0]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            return Http::response(['jobId' => 'job-err', 'state' => 'JOB_STATE_CREATED'], 200);
        }
        if (str_contains($url, 'app.bsky.video.getJobStatus')) {
            return Http::response(['error' => 'InternalServerError'], 500);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3joberr', 'cid' => 'bafjoberr'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // A failing getJobStatus bails immediately rather than sleeping to timeout.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'xrpc/app.bsky.video.getJobStatus'));
    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['embed']));
});

test('bluesky publisher retries the upload up to three times before giving up', function () {
    attachBlueskyVideo($this->post);
    config(['trypost.platforms.bluesky.video_poll_seconds' => 0]);

    $uploads = 0;
    Http::fake(function ($request) use (&$uploads) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            $uploads++;

            return Http::response(['jobId' => "job-{$uploads}", 'state' => 'JOB_STATE_CREATED'], 200);
        }
        if (str_contains($url, 'app.bsky.video.getJobStatus')) {
            return Http::response(['jobStatus' => ['jobId' => 'job', 'state' => 'JOB_STATE_FAILED', 'message' => 'permanent']], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3exhaust', 'cid' => 'bafex'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // Every attempt fails permanently → exactly VIDEO_UPLOAD_ATTEMPTS uploads, then text-only.
    expect($uploads)->toBe(3);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['embed']));
});

test('bluesky publisher times out and publishes text-only when the job never completes', function () {
    attachBlueskyVideo($this->post);
    config(['trypost.platforms.bluesky.video_poll_seconds' => 0]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            return Http::response(['jobId' => 'job-stuck', 'state' => 'JOB_STATE_CREATED'], 200);
        }
        if (str_contains($url, 'app.bsky.video.getJobStatus')) {
            // Never reaches a terminal state — exercises the poll timeout.
            return Http::response(['jobStatus' => ['jobId' => 'job-stuck', 'state' => 'JOB_STATE_RUNNING']], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3stuck', 'cid' => 'bafstuck'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['embed']));
});

test('bluesky publisher falls back to the entryway when the DID document is unavailable', function () {
    attachBlueskyVideo($this->post);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['error' => 'NotFound'], 500); // DID doc unavailable
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            return Http::response(['jobId' => 'job-fb', 'state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'baffb'], 'mimeType' => 'video/mp4', 'size' => 2048]], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3fb', 'cid' => 'baffb'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // With no DID doc, the upload-token audience falls back to the entryway host (bsky.social).
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'getServiceAuth')
            && str_contains($request->url(), 'aud=did%3Aweb%3Absky.social')
            && str_contains($request->url(), 'lxm=com.atproto.repo.uploadBlob');
    });
});

test('bluesky publisher resolves the PDS from a did:web document', function () {
    $this->socialAccount->update(['platform_user_id' => 'did:web:example.com']);
    attachBlueskyVideo($this->post);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'example.com/.well-known/did.json')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            return Http::response(['jobId' => 'job-web', 'state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafweb'], 'mimeType' => 'video/mp4', 'size' => 2048]], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:web:example.com/app.bsky.feed.post/3web', 'cid' => 'bafweb'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'example.com/.well-known/did.json'));
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'getServiceAuth')
            && str_contains($request->url(), 'aud=did%3Aweb%3Apds.example.host')
            && str_contains($request->url(), 'lxm=com.atproto.repo.uploadBlob');
    });
});

test('bluesky publisher builds the did:web document url from colon path segments', function () {
    $this->socialAccount->update(['platform_user_id' => 'did:web:example.com:user:alice']);
    attachBlueskyVideo($this->post);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'example.com/user/alice/did.json')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            return Http::response(['jobId' => 'job-seg', 'state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafseg'], 'mimeType' => 'video/mp4', 'size' => 2048]], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://x/app.bsky.feed.post/3seg', 'cid' => 'bafseg'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // did:web:example.com:user:alice → https://example.com/user/alice/did.json
    Http::assertSent(fn ($request) => str_contains($request->url(), 'example.com/user/alice/did.json'));
});

test('bluesky publisher scopes the status service-auth to the video service', function () {
    attachBlueskyVideo($this->post);

    fakeBlueskyVideoPipeline();

    $this->publisher->publish($this->postPlatform);

    // The getJobStatus token is minted for the video service DID, not the PDS.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'getServiceAuth')
            && str_contains($request->url(), 'aud=did%3Aweb%3Avideo.bsky.app')
            && str_contains($request->url(), 'lxm=app.bsky.video.getJobStatus');
    });
});

test('bluesky publisher picks the PDS entry even when other services come first', function () {
    attachBlueskyVideo($this->post);
    config(['trypost.platforms.bluesky.video_poll_seconds' => 0]);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            // A labeler service is listed before the PDS; resolution must skip it.
            return Http::response(['service' => [
                ['id' => '#atproto_labeler', 'type' => 'AtprotoLabeler', 'serviceEndpoint' => 'https://labeler.example'],
                ['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://real-pds.example.host'],
            ]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            return Http::response(['jobId' => 'job-pds', 'state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafpds'], 'mimeType' => 'video/mp4', 'size' => 2048]], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3pds', 'cid' => 'bafpds'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // The upload-token audience is the PDS host, not the labeler listed first.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'getServiceAuth')
            && str_contains($request->url(), 'aud=did%3Aweb%3Areal-pds.example.host')
            && str_contains($request->url(), 'lxm=com.atproto.repo.uploadBlob');
    });
});

test('bluesky publisher uploads a webm video with the correct content type', function () {
    attachBlueskyVideo($this->post, 'video/webm');

    fakeBlueskyVideoPipeline();

    $this->publisher->publish($this->postPlatform);

    // Bluesky accepts webm natively; send it as webm, not mislabeled mp4.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'xrpc/app.bsky.video.uploadVideo')
            && str_contains($request->url(), '.webm')
            && $request->hasHeader('Content-Type', 'video/webm');
    });
});

test('bluesky publisher uploads a mov video with the quicktime content type', function () {
    attachBlueskyVideo($this->post, 'video/quicktime');

    fakeBlueskyVideoPipeline();

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'xrpc/app.bsky.video.uploadVideo')
            && str_contains($request->url(), '.mov')
            && $request->hasHeader('Content-Type', 'video/quicktime');
    });
});

test('bluesky publisher sends an unsupported video format as mp4', function () {
    attachBlueskyVideo($this->post, 'video/x-matroska'); // .mkv is not one of Bluesky's four formats

    fakeBlueskyVideoPipeline();

    $this->publisher->publish($this->postPlatform);

    // Unknown formats fall back to mp4 and let the transcoder decide.
    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'xrpc/app.bsky.video.uploadVideo')
            && str_contains($request->url(), '.mp4')
            && $request->hasHeader('Content-Type', 'video/mp4');
    });
});

test('bluesky publisher polls when a 409 carries an in-flight job without a blob', function () {
    attachBlueskyVideo($this->post);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            return Http::response(['token' => 'service-auth-token'], 200);
        }
        if (str_contains($url, 'app.bsky.video.uploadVideo')) {
            // 409 already-exists, but the job is still processing (no blob yet) — must poll.
            return Http::response(['jobId' => 'job-409', 'state' => 'JOB_STATE_CREATED'], 409);
        }
        if (str_contains($url, 'app.bsky.video.getJobStatus')) {
            return Http::response(['jobStatus' => ['jobId' => 'job-409', 'state' => 'JOB_STATE_COMPLETED', 'blob' => ['$type' => 'blob', 'ref' => ['$link' => 'baf409poll'], 'mimeType' => 'video/mp4', 'size' => 2048]]], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3p409', 'cid' => 'bafp409'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // The 409 had no blob, so the job is polled and the completed blob is embedded.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'xrpc/app.bsky.video.getJobStatus'));
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }
        $embed = $request['record']['embed'] ?? null;

        return $embed && $embed['$type'] === 'app.bsky.embed.video'
            && data_get($embed, 'video.ref.$link') === 'baf409poll';
    });
});

test('bluesky publisher embeds images and skips the video when a post carries both', function () {
    $this->post->update([
        'media' => [
            ['id' => 'img', 'path' => 'media/2026-01/test.jpg', 'url' => 'https://example.com/test.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'test.jpg'],
            ['id' => 'vid', 'path' => 'media/2026-01/test.mp4', 'url' => 'https://example.com/test.mp4', 'mime_type' => 'video/mp4', 'original_filename' => 'test.mp4'],
        ],
    ]);

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'bsky_test_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'uploadBlob')) {
            return Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafimg'], 'mimeType' => 'image/jpeg', 'size' => 1024]], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3both', 'cid' => 'bafboth'], 200);
        }

        return Http::response(str_repeat('x', 1024), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // Images win; the embed is images and the video service is never touched.
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        return ($request['record']['embed']['$type'] ?? null) === 'app.bsky.embed.images';
    });
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'xrpc/app.bsky.video.uploadVideo'));
});

test('bluesky publisher publishes text-only when only the status token fails', function () {
    attachBlueskyVideo($this->post);

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'plc.directory')) {
            return Http::response(['service' => [['id' => '#atproto_pds', 'type' => 'AtprotoPersonalDataServer', 'serviceEndpoint' => 'https://pds.example.host']]], 200);
        }
        if (str_contains($url, 'getServiceAuth')) {
            // Upload token mints fine; only the getJobStatus token fails.
            return str_contains($url, 'lxm=app.bsky.video.getJobStatus')
                ? Http::response(['error' => 'AuthRequired'], 400)
                : Http::response(['token' => 'upload-token'], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3stok', 'cid' => 'bafstok'], 200);
        }

        return Http::response(str_repeat('v', 2048), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // Without the status token the upload can't be polled, so we never upload — degrade to text.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord') && ! isset($request['record']['embed']));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'xrpc/app.bsky.video.uploadVideo'));
});

test('bluesky publisher uploads an mpeg video with the correct content type', function () {
    attachBlueskyVideo($this->post, 'video/mpeg');

    fakeBlueskyVideoPipeline();

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'xrpc/app.bsky.video.uploadVideo')
            && str_contains($request->url(), '.mpeg')
            && $request->hasHeader('Content-Type', 'video/mpeg');
    });
});

test('bluesky publisher uploads a gif without optimizing it', function () {
    $this->post->update([
        'media' => [['id' => 'gif', 'path' => 'media/2026-01/test.gif', 'url' => 'https://example.com/test.gif', 'mime_type' => 'image/gif', 'original_filename' => 'test.gif']],
    ]);

    // GIFs are passed through untouched — the optimizer must not run.
    $this->mock(MediaOptimizer::class)->shouldReceive('optimizeImage')->never();

    Http::fake(function ($request) {
        $url = $request->url();

        if (str_contains($url, 'uploadBlob')) {
            return Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafgif'], 'mimeType' => 'image/gif', 'size' => 1024]], 200);
        }
        if (str_contains($url, 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3gif', 'cid' => 'bafgif'], 200);
        }

        return Http::response(str_repeat('g', 1024), 200);
    });

    $this->publisher->publish($this->postPlatform);

    // The GIF is uploaded as-is with its original content type.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'uploadBlob')
        && $request->hasHeader('Content-Type', 'image/gif'));
});

test('bluesky publisher attaches an external card with a thumb for a bare link', function () {
    $this->post->update(['content' => 'read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            // A public IP literal lets SafeHttpFetcher's SSRF guard pass without a real DNS lookup.
            imageUrl: 'https://93.184.216.34/card.jpg',
        ));

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'bsky_thumb_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'uploadBlob')) {
            return Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafthumb'], 'mimeType' => 'image/jpeg', 'size' => 1024]], 200);
        }

        if (str_contains($request->url(), 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz', 'cid' => 'bafyreiabc123'], 200);
        }

        return Http::response(str_repeat('x', 1024), 200); // og:image download
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.external'
            && $embed['external']['uri'] === 'https://example.com/article'
            && $embed['external']['title'] === 'The Article'
            && $embed['external']['description'] === 'A great read'
            && data_get($embed, 'external.thumb.ref.$link') === 'bafthumb';
    });
});

test('bluesky publisher builds an external card without a thumb when there is no image', function () {
    $this->post->update(['content' => 'read this https://example.com/article']);

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
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.external'
            && ! isset($embed['external']['thumb']);
    });
});

test('bluesky publisher keeps the card when the thumb upload fails', function () {
    $this->post->update(['content' => 'read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            // A public IP literal lets SafeHttpFetcher's SSRF guard pass without a real DNS lookup.
            imageUrl: 'https://93.184.216.34/card.jpg',
        ));

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'bsky_thumb_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'uploadBlob')) {
            return Http::response(['error' => 'InternalServerError'], 500);
        }

        if (str_contains($request->url(), 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz', 'cid' => 'bafyreiabc123'], 200);
        }

        return Http::response(str_repeat('x', 1024), 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.external'
            && ! isset($embed['external']['thumb']);
    });
});

test('bluesky publisher does not consult the link card fetcher when media is present', function () {
    $this->post->update([
        'content' => 'has media and a link https://example.com/article',
        'media' => [[
            'id' => 'test-media-id',
            'path' => 'media/2026-01/test-image.jpg',
            'url' => 'https://example.com/media/2026-01/test-image.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'test.jpg',
        ]],
    ]);

    $this->mock(LinkCardFetcher::class)->shouldReceive('fetch')->never();

    $this->mock(MediaOptimizer::class)
        ->shouldReceive('optimizeImage')
        ->andReturnUsing(fn () => tap(tempnam(sys_get_temp_dir(), 'bsky_img_'), fn ($f) => file_put_contents($f, str_repeat('x', 1024))));

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'uploadBlob')) {
            return Http::response(['blob' => ['$type' => 'blob', 'ref' => ['$link' => 'bafimg'], 'mimeType' => 'image/jpeg', 'size' => 1024]], 200);
        }

        if (str_contains($request->url(), 'createRecord')) {
            return Http::response(['uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz', 'cid' => 'bafyreiabc123'], 200);
        }

        return Http::response(str_repeat('x', 1024), 200);
    });

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'createRecord')
        && ($request['record']['embed']['$type'] ?? null) === 'app.bsky.embed.images');
});

test('bluesky publisher blocks the thumb download when the card image points at a private address', function () {
    $this->post->update(['content' => 'read this https://example.com/article']);

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
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.external'
            && ! isset($embed['external']['thumb']);
    });

    // The SSRF guard must block the download before any request to the private address fires.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('bluesky publisher does not follow a redirect on the card thumb download', function () {
    $this->post->update(['content' => 'read this https://example.com/article']);

    $this->mock(LinkCardFetcher::class)
        ->shouldReceive('fetch')
        ->once()
        ->andReturn(new LinkCardMetadata(
            uri: 'https://example.com/article',
            title: 'The Article',
            description: 'A great read',
            // The host guard passes (public IP literal); the redirect target does not.
            imageUrl: 'https://93.184.216.34/card.jpg',
        ));

    Http::fake([
        'https://93.184.216.34/card.jpg' => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal.jpg']),
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $embed = $request['record']['embed'] ?? null;

        return $embed
            && $embed['$type'] === 'app.bsky.embed.external'
            && ! isset($embed['external']['thumb']);
    });

    // The redirect to the internal address must never be followed.
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('bluesky publisher does not attach a card when a non-embeddable media item is attached', function () {
    $this->post->update([
        'content' => 'read this https://example.com/article',
        'media' => [[
            'id' => 'doc',
            'path' => 'media/2026-01/f.pdf',
            'url' => 'https://example.com/f.pdf',
            'mime_type' => 'application/pdf',
            'original_filename' => 'f.pdf',
        ]],
    ]);

    // A PDF is neither image nor video, so no embed is built for it; the card
    // gate must still short-circuit because media is attached to the post.
    $this->mock(LinkCardFetcher::class)->shouldReceive('fetch')->never();

    Http::fake([
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $record = $request['record'];

        return ! isset($record['embed'])
            && collect($record['facets'] ?? [])->contains(
                fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#link'
            );
    });
});

test('bluesky publisher publishes with only a facet when no card is available', function () {
    $this->post->update(['content' => 'read this https://example.com/article']);

    // beforeEach default mock returns null (no card).
    Http::fake([
        config('trypost.platforms.bluesky.default_service').'/xrpc/com.atproto.repo.createRecord' => Http::response([
            'uri' => 'at://did:plc:testuser123/app.bsky.feed.post/3abc123xyz',
            'cid' => 'bafyreiabc123',
        ], 200),
    ]);

    $this->publisher->publish($this->postPlatform);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'createRecord')) {
            return false;
        }

        $record = $request['record'];

        return ! isset($record['embed'])
            && collect($record['facets'] ?? [])->contains(
                fn ($facet) => $facet['features'][0]['$type'] === 'app.bsky.richtext.facet#link'
            );
    });
});
