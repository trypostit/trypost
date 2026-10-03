<?php

declare(strict_types=1);

use App\Dto\RemoteFile;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Post\ExternalMedia\BlueskyMediaResolver;
use App\Services\Post\ExternalMedia\CoverOnlyResolver;
use App\Services\Post\ExternalMedia\ExternalMediaResolverFactory;
use App\Services\Post\ExternalMedia\FacebookMediaResolver;
use App\Services\Post\ExternalMedia\InstagramMediaResolver;
use App\Services\Post\ExternalMedia\MastodonMediaResolver;
use App\Services\Post\ExternalMedia\PinterestMediaResolver;
use App\Services\Post\ExternalMedia\ThreadsMediaResolver;
use App\Services\Post\ExternalMedia\XMediaResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

function resolverPublication(SocialAccount $account, array $attributes = []): AnalyticsPublication
{
    return AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        ...$attributes,
    ]);
}

/**
 * @param  list<RemoteFile>  $files
 * @return list<string>
 */
function resolvedUrls(array $files): array
{
    return array_map(fn (RemoteFile $file): string => $file->url, $files);
}

test('the factory picks one resolver per network', function (Platform $platform, string $resolver) {
    expect(app(ExternalMediaResolverFactory::class)->for($platform))->toBeInstanceOf($resolver);
})->with([
    [Platform::Instagram, InstagramMediaResolver::class],
    [Platform::InstagramFacebook, InstagramMediaResolver::class],
    [Platform::Threads, ThreadsMediaResolver::class],
    [Platform::Facebook, FacebookMediaResolver::class],
    [Platform::TikTok, CoverOnlyResolver::class],
    [Platform::YouTube, CoverOnlyResolver::class],
]);

test('instagram returns carousel children in order and drops items without a file', function () {
    $account = SocialAccount::factory()->instagram()->create();
    Http::fake([config('trypost.platforms.instagram.graph_api').'/ig-carousel*' => Http::response([
        'media_type' => 'CAROUSEL_ALBUM',
        'children' => ['data' => [
            ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.example.test/a.jpg'],
            ['media_type' => 'VIDEO', 'media_url' => 'https://scontent.example.test/b.mp4'],
            ['media_type' => 'VIDEO'],
        ]],
    ])]);

    $files = app(InstagramMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'ig-carousel']));

    expect(resolvedUrls($files))->toBe(['https://scontent.example.test/a.jpg', 'https://scontent.example.test/b.mp4']);
    Http::assertSent(fn ($request): bool => str_contains((string) $request['fields'], 'children{media_type,media_url}'));
});

test('instagram through facebook uses the facebook graph host for a single reel', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::InstagramFacebook]);
    Http::fake([config('trypost.platforms.instagram-facebook.graph_api').'/ig-reel*' => Http::response([
        'media_type' => 'VIDEO',
        'media_url' => 'https://scontent.example.test/reel.mp4',
    ])]);

    $files = app(InstagramMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'ig-reel']));

    expect(resolvedUrls($files))->toBe(['https://scontent.example.test/reel.mp4']);
});

test('instagram media with copyrighted audio exposes no file', function () {
    $account = SocialAccount::factory()->instagram()->create();
    Http::fake([config('trypost.platforms.instagram.graph_api').'/ig-music*' => Http::response(['media_type' => 'VIDEO'])]);

    expect(app(InstagramMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'ig-music'])))->toBe([]);
});

test('threads returns carousel children in order', function () {
    $account = SocialAccount::factory()->threads()->create();
    Http::fake([config('trypost.platforms.threads.graph_api').'/th-1*' => Http::response([
        'media_type' => 'CAROUSEL_ALBUM',
        'children' => ['data' => [
            ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.example.test/t1.jpg'],
            ['media_type' => 'IMAGE', 'media_url' => 'https://scontent.example.test/t2.jpg'],
        ]],
    ])]);

    $files = app(ThreadsMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'th-1']));

    expect(resolvedUrls($files))->toBe(['https://scontent.example.test/t1.jpg', 'https://scontent.example.test/t2.jpg']);
});

test('facebook returns album photos in order and resolves video sources', function () {
    $account = SocialAccount::factory()->facebook()->create();
    $api = config('trypost.platforms.facebook.graph_api');
    Http::fake([
        "{$api}/page_post-1*" => Http::response(['attachments' => ['data' => [[
            'type' => 'album',
            'subattachments' => ['data' => [
                ['media_type' => 'photo', 'media' => ['image' => ['src' => 'https://scontent.example.test/p1.jpg']]],
                ['media_type' => 'video', 'type' => 'video', 'target' => ['id' => 'video-7'], 'media' => ['image' => ['src' => 'https://scontent.example.test/thumb.jpg']]],
            ]],
        ]]]]),
        "{$api}/video-7*" => Http::response(['source' => 'https://video.example.test/v7.mp4']),
    ]);

    $files = app(FacebookMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'page_post-1']));

    expect(resolvedUrls($files))->toBe(['https://scontent.example.test/p1.jpg', 'https://video.example.test/v7.mp4']);
});

test('a facebook text post exposes no file', function () {
    $account = SocialAccount::factory()->facebook()->create();
    Http::fake([config('trypost.platforms.facebook.graph_api').'/page_post-2*' => Http::response(['id' => 'page_post-2'])]);

    expect(app(FacebookMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'page_post-2'])))->toBe([]);
});

test('a facebook link share keeps its preview image out of the post media', function () {
    $account = SocialAccount::factory()->facebook()->create();
    Http::fake([config('trypost.platforms.facebook.graph_api').'/page_post-3*' => Http::response(['attachments' => ['data' => [[
        'media_type' => 'link',
        'type' => 'share',
        'media' => ['image' => ['src' => 'https://external.example.test/og-image.jpg']],
        'target' => ['url' => 'https://example.test/article'],
    ]]]])]);

    expect(app(FacebookMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'page_post-3'])))->toBe([]);
});

test('a facebook single photo post returns its image', function () {
    $account = SocialAccount::factory()->facebook()->create();
    Http::fake([config('trypost.platforms.facebook.graph_api').'/page_post-4*' => Http::response(['attachments' => ['data' => [[
        'media_type' => 'photo',
        'type' => 'photo',
        'media' => ['image' => ['src' => 'https://scontent.example.test/single.jpg']],
    ]]]])]);

    expect(resolvedUrls(app(FacebookMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'page_post-4']))))
        ->toBe(['https://scontent.example.test/single.jpg']);
});

test('a graph error surfaces as a request exception', function () {
    $account = SocialAccount::factory()->instagram()->create();
    Http::fake([config('trypost.platforms.instagram.graph_api').'/*' => Http::response(['error' => ['code' => 4]], 429)]);

    expect(fn () => app(InstagramMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => 'x'])))
        ->toThrow(RequestException::class);
});

test('x picks photos and the highest bitrate mp4 without calling the network', function () {
    Http::fake();
    $account = SocialAccount::factory()->x()->create();
    $publication = resolverPublication($account, ['provider_metadata' => ['media' => [
        ['type' => 'photo', 'url' => 'https://pbs.example.test/1.jpg'],
        ['type' => 'video', 'variants' => [
            ['bit_rate' => 256000, 'content_type' => 'video/mp4', 'url' => 'https://video.example.test/low.mp4'],
            ['bit_rate' => 2176000, 'content_type' => 'video/mp4', 'url' => 'https://video.example.test/high.mp4'],
            ['content_type' => 'application/x-mpegURL', 'url' => 'https://video.example.test/pl.m3u8'],
        ]],
    ]]]);

    expect(resolvedUrls(app(XMediaResolver::class)->files($account, $publication)))
        ->toBe(['https://pbs.example.test/1.jpg', 'https://video.example.test/high.mp4'])
        ->and(app(ExternalMediaResolverFactory::class)->for(Platform::X))->toBeInstanceOf(XMediaResolver::class);
    Http::assertNothingSent();
});

test('an x publication collected before media was stored exposes no file', function () {
    $account = SocialAccount::factory()->x()->create();

    expect(app(XMediaResolver::class)->files($account, resolverPublication($account, ['provider_metadata' => null])))->toBe([]);
});

test('the factory covers the open networks', function (Platform $platform, string $resolver) {
    expect(app(ExternalMediaResolverFactory::class)->for($platform))->toBeInstanceOf($resolver);
})->with([
    [Platform::Mastodon, MastodonMediaResolver::class],
    [Platform::Bluesky, BlueskyMediaResolver::class],
    [Platform::Pinterest, PinterestMediaResolver::class],
]);

test('mastodon returns image video and gifv attachments in order', function () {
    $account = SocialAccount::factory()->mastodon()->create(['scopes' => ['read']]);
    $instance = $account->mastodonInstance();
    Http::fake(["{$instance}/api/v1/statuses/1100" => Http::response(['media_attachments' => [
        ['type' => 'image', 'url' => 'https://files.example.test/a.png'],
        ['type' => 'audio', 'url' => 'https://files.example.test/b.mp3'],
        ['type' => 'gifv', 'url' => 'https://files.example.test/c.mp4'],
        ['type' => 'video', 'url' => null],
    ]])]);

    $files = app(MastodonMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => '1100']));

    expect(resolvedUrls($files))->toBe(['https://files.example.test/a.png', 'https://files.example.test/c.mp4']);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization'));
});

test('mastodon reads a public status without a token when the account lacks a read scope', function () {
    $account = SocialAccount::factory()->mastodon()->create(['scopes' => ['write:statuses'], 'meta' => []]);
    $instance = rtrim((string) config('trypost.platforms.mastodon.default_instance'), '/');
    Http::fake(["{$instance}/api/v1/statuses/1200" => Http::response(['media_attachments' => [
        ['type' => 'image', 'url' => 'https://files.example.test/d.png'],
    ]])]);

    $files = app(MastodonMediaResolver::class)->files($account, resolverPublication($account, ['remote_id' => '1200']));

    expect(resolvedUrls($files))->toBe(['https://files.example.test/d.png']);
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

test('bluesky returns full size images and nothing for a video', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    $appView = rtrim((string) config('trypost.platforms.bluesky.public_appview'), '/');
    Http::fake(["{$appView}/xrpc/app.bsky.feed.getPosts*" => Http::sequence()
        ->push(['posts' => [['embed' => ['images' => [
            ['fullsize' => 'https://cdn.bsky.example.test/1.jpg'],
            ['fullsize' => 'https://cdn.bsky.example.test/2.jpg'],
        ]]]]])
        ->push(['posts' => [['embed' => ['playlist' => 'https://video.bsky.example.test/pl.m3u8', 'thumbnail' => 'https://video.bsky.example.test/t.jpg']]]])]);

    $resolver = app(BlueskyMediaResolver::class);

    expect(resolvedUrls($resolver->files($account, resolverPublication($account, ['remote_id' => 'rkey1']))))
        ->toBe(['https://cdn.bsky.example.test/1.jpg', 'https://cdn.bsky.example.test/2.jpg'])
        ->and($resolver->files($account, resolverPublication($account, ['remote_id' => 'rkey2'])))->toBe([]);
});

test('pinterest returns the largest image, the video file and carousel items', function () {
    $account = SocialAccount::factory()->pinterest()->create();
    $api = config('trypost.platforms.pinterest.api');
    Http::fake([
        "{$api}/pins/pin-image" => Http::response(['media' => ['media_type' => 'image', 'images' => ['1200x' => ['url' => 'https://i.pinimg.example.test/o.jpg']]]]),
        "{$api}/pins/pin-video" => Http::response(['media' => ['media_type' => 'video', 'video_url' => 'https://v.pinimg.example.test/v.mp4', 'images' => ['1200x' => ['url' => 'https://i.pinimg.example.test/cover.jpg']]]]),
        "{$api}/pins/pin-carousel" => Http::response(['media' => ['media_type' => 'multiple_images', 'items' => [
            ['item_type' => 'image', 'images' => ['1200x' => ['url' => 'https://i.pinimg.example.test/1.jpg']]],
            ['item_type' => 'image', 'images' => ['1200x' => ['url' => 'https://i.pinimg.example.test/2.jpg']]],
        ]]]),
    ]);
    $resolver = app(PinterestMediaResolver::class);

    expect(resolvedUrls($resolver->files($account, resolverPublication($account, ['remote_id' => 'pin-image']))))->toBe(['https://i.pinimg.example.test/o.jpg'])
        ->and(resolvedUrls($resolver->files($account, resolverPublication($account, ['remote_id' => 'pin-video']))))->toBe(['https://v.pinimg.example.test/v.mp4'])
        ->and(resolvedUrls($resolver->files($account, resolverPublication($account, ['remote_id' => 'pin-carousel']))))->toBe(['https://i.pinimg.example.test/1.jpg', 'https://i.pinimg.example.test/2.jpg']);
});

test('a pinterest video without a file url exposes no file and a mixed pin keeps only its images', function () {
    $account = SocialAccount::factory()->pinterest()->create();
    $api = config('trypost.platforms.pinterest.api');
    Http::fake([
        "{$api}/pins/pin-limited" => Http::response(['media' => ['media_type' => 'video', 'video_url' => null, 'images' => ['1200x' => ['url' => 'https://i.pinimg.example.test/cover.jpg']]]]),
        "{$api}/pins/pin-mixed" => Http::response(['media' => ['media_type' => 'multiple_mixed', 'items' => [
            ['item_type' => 'video', 'video_url' => null, 'cover_image_url' => 'https://i.pinimg.example.test/c.jpg'],
            ['item_type' => 'image', 'images' => ['1200x' => ['url' => 'https://i.pinimg.example.test/3.jpg']]],
        ]]]),
    ]);
    $resolver = app(PinterestMediaResolver::class);

    expect($resolver->files($account, resolverPublication($account, ['remote_id' => 'pin-limited'])))->toBe([])
        ->and(resolvedUrls($resolver->files($account, resolverPublication($account, ['remote_id' => 'pin-mixed']))))->toBe(['https://i.pinimg.example.test/3.jpg']);
});
