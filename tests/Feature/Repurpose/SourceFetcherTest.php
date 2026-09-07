<?php

declare(strict_types=1);

use App\Enums\Repurpose\SourceFormat;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Repurpose\SourceFetchException;
use App\Models\SocialAccount;
use App\Services\Repurpose\SourceFetcherFactory;
use Illuminate\Support\Facades\Http;

function instagramGraph(): string
{
    return config('trypost.platforms.instagram.graph_api');
}

function facebookGraph(): string
{
    return config('trypost.platforms.facebook.graph_api');
}

function fetchFor(SocialAccount $account, array $formats, $since = null): array
{
    return app(SourceFetcherFactory::class)->for($account)->fetch($account, $since, $formats);
}

test('instagram tags reels and feed videos apart by product type', function () {
    Http::fake([
        instagramGraph().'/*/media*' => Http::response(['data' => [
            ['id' => 'r1', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'media_url' => 'https://cdn.example.com/r.mp4', 'caption' => 'Reel', 'permalink' => 'https://instagram.com/p/1', 'timestamp' => '2026-09-01T10:00:00+0000'],
            ['id' => 'f1', 'media_type' => 'VIDEO', 'media_product_type' => 'FEED', 'media_url' => 'https://cdn.example.com/f.mp4', 'caption' => 'Feed', 'permalink' => 'https://instagram.com/p/2', 'timestamp' => '2026-09-01T11:00:00+0000'],
            ['id' => 'i1', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED', 'media_url' => 'https://cdn.example.com/i.jpg', 'caption' => 'Pic', 'timestamp' => '2026-09-01T12:00:00+0000'],
        ]]),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $media = fetchFor($account, [SourceFormat::Reel, SourceFormat::Video]);

    expect($media)->toHaveCount(3)
        ->and($media[0]->format)->toBe(SourceFormat::Reel)
        ->and($media[1]->format)->toBe(SourceFormat::Video)
        ->and($media[2]->format)->toBeNull()
        ->and($media[2]->format)->toBeNull();
});

test('instagram only calls the stories edge when stories are watched', function () {
    Http::fake([instagramGraph().'/*' => Http::response(['data' => []])]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    fetchFor($account, [SourceFormat::Reel]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/stories'));

    fetchFor($account, [SourceFormat::Story]);

    Http::assertSent(fn ($request) => str_contains($request->url(), '/stories'));
});

test('an instagram account connected through facebook uses the facebook graph host', function () {
    $graph = config('trypost.platforms.instagram-facebook.graph_api');
    Http::fake(["{$graph}/*" => Http::response(['data' => []])]);

    $account = SocialAccount::factory()->create(['platform' => Platform::InstagramFacebook]);

    fetchFor($account, [SourceFormat::Reel]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), $graph));
});

test('facebook reads reels and videos from their own edges and drops the overlap', function () {
    Http::fake([
        facebookGraph().'/*/video_reels*' => Http::response(['data' => [
            ['id' => 'v1', 'source' => 'https://cdn.example.com/r.mp4', 'description' => 'Reel', 'permalink_url' => '/watch/1', 'created_time' => '2026-09-02T10:00:00+0000'],
        ]]),
        facebookGraph().'/*/videos*' => Http::response(['data' => [
            ['id' => 'v1', 'source' => 'https://cdn.example.com/r.mp4', 'description' => 'Reel', 'permalink_url' => '/watch/1', 'created_time' => '2026-09-02T10:00:00+0000'],
            ['id' => 'v2', 'source' => 'https://cdn.example.com/v.mp4', 'description' => 'Video', 'permalink_url' => '/watch/2', 'created_time' => '2026-09-02T11:00:00+0000'],
        ]]),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Facebook]);

    $media = fetchFor($account, [SourceFormat::Reel, SourceFormat::Video]);

    expect($media)->toHaveCount(2)
        ->and($media[0]->id)->toBe('v1')
        ->and($media[0]->format)->toBe(SourceFormat::Reel)
        ->and($media[1]->id)->toBe('v2')
        ->and($media[1]->format)->toBe(SourceFormat::Video);
});

test('facebook stories resolve the downloadable file behind each story', function () {
    Http::fake([
        facebookGraph().'/*/stories*' => Http::response(['data' => [
            ['post_id' => 's1', 'status' => 'PUBLISHED', 'media_type' => 'video', 'media_id' => 'vid-1', 'url' => 'https://facebook.com/stories/1', 'creation_time' => '2026-09-03T10:00:00+0000'],
            ['post_id' => 's2', 'status' => 'PUBLISHED', 'media_type' => 'photo', 'media_id' => 'pic-1', 'url' => 'https://facebook.com/stories/2', 'creation_time' => '2026-09-03T11:00:00+0000'],
        ]]),
        facebookGraph().'/vid-1*' => Http::response(['source' => 'https://cdn.example.com/story.mp4']),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Facebook]);

    $media = fetchFor($account, [SourceFormat::Story]);

    expect($media)->toHaveCount(1)
        ->and($media[0]->id)->toBe('s1')
        ->and($media[0]->format)->toBe(SourceFormat::Story)
        ->and($media[0]->downloadUrl)->toBe('https://cdn.example.com/story.mp4');
});

test('a since timestamp is sent to the api', function () {
    Http::fake([instagramGraph().'/*' => Http::response(['data' => []])]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);
    $since = now()->subDay();

    fetchFor($account, [SourceFormat::Reel], $since);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'since='.$since->getTimestamp()));
});

test('a failed response throws so the caller can record it', function () {
    Http::fake([instagramGraph().'/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    expect(fn () => fetchFor($account, [SourceFormat::Reel]))
        ->toThrow(RuntimeException::class, 'Invalid token');
});

test('an unsupported platform cannot be a source', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);

    expect(fn () => app(SourceFetcherFactory::class)->for($account))
        ->toThrow(InvalidArgumentException::class);
});

test('a standalone instagram video with no product type still counts as a reel', function () {
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/*' => Http::response(['data' => [
            ['id' => '1', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn/v.mp4', 'caption' => 'Hi'],
            ['id' => '2', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn/i.jpg'],
        ]]),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $media = app(SourceFetcherFactory::class)->for($account)->fetch($account, null, [SourceFormat::Reel]);

    expect($media)->toHaveCount(2)
        ->and($media[0]->format)->toBe(SourceFormat::Reel)
        ->and($media[1]->format)->toBeNull();
});

test('a story is a story because of the edge it came from, not a field', function () {
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/*/media*' => Http::response(['data' => []]),
        config('trypost.platforms.instagram.graph_api').'/*/stories*' => Http::response(['data' => [
            ['id' => 's1', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn/s.mp4'],
        ]]),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $media = app(SourceFetcherFactory::class)->for($account)->fetch($account, null, [SourceFormat::Story]);

    expect($media)->toHaveCount(1)
        ->and($media[0]->format)->toBe(SourceFormat::Story);
});

test('a field the token cannot read costs the caption, not the whole source', function () {
    $responses = [
        Http::response(['error' => ['code' => 100, 'message' => '(#100) Tried accessing nonexistent field (caption)']], 400),
        Http::response(['data' => [
            ['id' => '1', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn/v.mp4', 'permalink' => 'https://instagram.com/p/1'],
        ]]),
    ];

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/*' => function () use (&$responses) {
            return array_shift($responses);
        },
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $media = app(SourceFetcherFactory::class)->for($account)->fetch($account, null, [SourceFormat::Reel]);

    expect($media)->toHaveCount(1)
        ->and($media[0]->format)->toBe(SourceFormat::Reel)
        ->and($media[0]->downloadUrl)->toBe('https://cdn/v.mp4')
        ->and($media[0]->caption)->toBe('');

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'media_product_type'));
    Http::assertSent(fn ($request) => ! str_contains((string) $request->url(), 'media_product_type')
        && str_contains((string) $request->url(), 'media_type'));
});

test('an error that is not about fields is not retried', function () {
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/*' => Http::response(
            ['error' => ['code' => 190, 'message' => 'Invalid OAuth access token']],
            401,
        ),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    expect(fn () => app(SourceFetcherFactory::class)->for($account)->fetch($account, null, [SourceFormat::Reel]))
        ->toThrow(SourceFetchException::class);

    Http::assertSentCount(1);
});

test('facebook only resolves the file for a published video story', function () {
    Http::fake([
        config('trypost.platforms.facebook.graph_api').'/*/stories*' => Http::response(['data' => [
            ['post_id' => 'p1', 'status' => 'PUBLISHED', 'media_type' => 'video', 'media_id' => 'v1', 'url' => 'https://fb/1'],
            ['post_id' => 'p2', 'status' => 'PUBLISHED', 'media_type' => 'photo', 'media_id' => 'ph1', 'url' => 'https://fb/2'],
            ['post_id' => 'p3', 'status' => 'ARCHIVED', 'media_type' => 'video', 'media_id' => 'v2', 'url' => 'https://fb/3'],
        ]]),
        config('trypost.platforms.facebook.graph_api').'/v1*' => Http::response(['source' => 'https://cdn/v1.mp4']),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Facebook]);

    $media = app(SourceFetcherFactory::class)->for($account)->fetch($account, null, [SourceFormat::Story]);

    expect($media)->toHaveCount(1)
        ->and($media[0]->id)->toBe('p1')
        ->and($media[0]->downloadUrl)->toBe('https://cdn/v1.mp4');

    Http::assertSentCount(2);
});

test('the story listing is bounded and filtered by the watermark', function () {
    Http::fake([config('trypost.platforms.facebook.graph_api').'/*' => Http::response(['data' => []])]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Facebook]);
    $since = now()->subDay();

    app(SourceFetcherFactory::class)->for($account)->fetch($account, $since, [SourceFormat::Story]);

    Http::assertSent(fn ($request) => str_contains((string) $request->url(), 'limit=25')
        && str_contains((string) $request->url(), 'since='.$since->getTimestamp()));
});

test('a page watched for feed videos alone does not pick up its reels', function () {
    Http::fake([
        facebookGraph().'/*/video_reels*' => Http::response(['data' => [
            ['id' => 'v1', 'source' => 'https://cdn.example.com/r.mp4', 'description' => 'Reel', 'permalink_url' => '/watch/1', 'created_time' => '2026-09-02T10:00:00+0000'],
        ]]),
        facebookGraph().'/*/videos*' => Http::response(['data' => [
            ['id' => 'v1', 'source' => 'https://cdn.example.com/r.mp4', 'description' => 'Reel', 'permalink_url' => '/watch/1', 'created_time' => '2026-09-02T10:00:00+0000'],
            ['id' => 'v2', 'source' => 'https://cdn.example.com/v.mp4', 'description' => 'Video', 'permalink_url' => '/watch/2', 'created_time' => '2026-09-02T11:00:00+0000'],
        ]]),
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Facebook]);

    $media = fetchFor($account, [SourceFormat::Video]);

    expect($media)->toHaveCount(1)
        ->and($media[0]->id)->toBe('v2');
});

test('a token that cannot read a field falls back to the public set', function () {
    $attempt = 0;

    Http::fake([
        instagramGraph().'/*/media*' => function () use (&$attempt) {
            $attempt++;

            // Graph rejects the whole read when one requested field is not
            // available to the token's login type, answering with code 100.
            return $attempt === 1
                ? Http::response(['error' => ['code' => 100, 'message' => 'Unsupported get request']], 400)
                : Http::response(['data' => [[
                    'id' => 'm1',
                    'media_type' => 'VIDEO',
                    'media_url' => 'https://cdn.example.com/v.mp4',
                    'permalink' => 'https://instagram.com/p/1',
                    'timestamp' => '2026-09-02T10:00:00+0000',
                ]]]);
        },
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $media = fetchFor($account, [SourceFormat::Reel]);

    expect($attempt)->toBe(2)
        ->and($media)->toHaveCount(1)
        ->and($media[0]->id)->toBe('m1')
        // The reduced set carries neither media_product_type nor caption, so
        // every video reads as a Reel and the caption arrives empty. That is the
        // documented cost of not going dark, not an oversight.
        ->and($media[0]->format)->toBe(SourceFormat::Reel)
        ->and($media[0]->caption)->toBe('');
});

test('a graph failure that is not an unknown field is not retried', function () {
    $attempt = 0;

    Http::fake([
        instagramGraph().'/*/media*' => function () use (&$attempt) {
            $attempt++;

            return Http::response(['error' => ['code' => 190, 'message' => 'Invalid token']], 400);
        },
    ]);

    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    expect(fn () => fetchFor($account, [SourceFormat::Reel]))->toThrow(SourceFetchException::class)
        ->and($attempt)->toBe(1);
});
