<?php

declare(strict_types=1);

use App\Actions\Analytics\SyncTryPostPublication;
use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Actions\Post\AssignTikTokVideoId;
use App\Actions\Post\ImportExternalPosts;
use App\Dto\Analytics\DiscoveredPublication;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\PostPlatform\ContentType;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

function tiktokPostAwaitingVideo(SocialAccount $account, string $platformPostId = 'v_pub_url~v2-1.pending'): Post
{
    return Post::factory()->forAccount($account, ContentType::TikTokVideo)->published()->create([
        'platform_post_id' => $platformPostId,
        'platform_url' => 'https://www.tiktok.com/@tiktoker',
    ]);
}

test('a post without an analytics publication gets the video id, its url and a publication', function () {
    $post = tiktokPostAwaitingVideo(SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']));

    app(AssignTikTokVideoId::class)->handle($post, '7694860629638940686');

    expect($post->fresh())
        ->platform_post_id->toBe('7694860629638940686')
        ->platform_url->toBe('https://www.tiktok.com/@tiktoker/video/7694860629638940686')
        ->and(AnalyticsPublication::query()->where('post_id', $post->id)->sole())
        ->remote_id->toBe('7694860629638940686')
        ->origin->toBe(PublicationOrigin::TryPost)
        ->permalink->toBe('https://www.tiktok.com/@tiktoker/video/7694860629638940686');
});

test('a post deleted before its video id is assigned is left as it is', function () {
    $post = tiktokPostAwaitingVideo(SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']));
    Post::query()->whereKey($post->id)->delete();

    app(AssignTikTokVideoId::class)->handle($post, '7694860629638940686');

    expect(AnalyticsPublication::query()->exists())->toBeFalse();
});

test('a post without an analytics publication takes its video back from the copy the importer made', function () {
    $account = SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']);
    $post = tiktokPostAwaitingVideo($account);
    $video = app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: '7694860629638940686',
        publishedAt: now()->subDays(2)->toImmutable(),
        contentType: PublicationContentType::Video,
        permalink: 'https://www.tiktok.com/@tiktoker/video/7694860629638940686?share=1',
    ));
    ImportExternalPosts::execute($account);
    $imported = Post::query()->imported()->sole();

    app(AssignTikTokVideoId::class)->handle($post, '7694860629638940686');

    expect(Post::query()->whereKey($imported->id)->exists())->toBeFalse()
        ->and(AnalyticsPublication::query()->sole())
        ->post_id->toBe($post->id)
        ->remote_id->toBe('7694860629638940686')
        ->permalink->toBe('https://www.tiktok.com/@tiktoker/video/7694860629638940686?share=1')
        ->and(AnalyticsPublication::query()->whereKey($video->id)->exists())->toBeFalse();
});

test('a post without an analytics publication is refused a video another TryPost post holds', function () {
    $account = SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']);
    $post = tiktokPostAwaitingVideo($account);
    app(SyncTryPostPublication::class)->handle(tiktokPostAwaitingVideo($account, '7694860629638940686'));

    expect(fn () => app(AssignTikTokVideoId::class)->handle($post, '7694860629638940686'))
        ->toThrow(LogicException::class);

    expect($post->fresh()->platform_post_id)->toBe('v_pub_url~v2-1.pending')
        ->and(AnalyticsPublication::query()->where('post_id', $post->id)->exists())->toBeFalse();
});

test('a channel without a username keeps the post url it had', function () {
    $post = tiktokPostAwaitingVideo(SocialAccount::factory()->tiktok()->create(['username' => null]));

    app(AssignTikTokVideoId::class)->handle($post, '7694860629638940686');

    expect($post->fresh())
        ->platform_post_id->toBe('7694860629638940686')
        ->platform_url->toBe('https://www.tiktok.com/@tiktoker');
});

test('a video held by another TryPost post is refused and nothing is written', function () {
    $account = SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']);
    $post = tiktokPostAwaitingVideo($account);
    $publication = app(SyncTryPostPublication::class)->handle($post);
    app(SyncTryPostPublication::class)->handle(tiktokPostAwaitingVideo($account, '7694860629638940686'));

    expect(fn () => app(AssignTikTokVideoId::class)->handle($post, '7694860629638940686'))
        ->toThrow(LogicException::class);

    expect($post->fresh()->platform_post_id)->toBe('v_pub_url~v2-1.pending')
        ->and($publication->fresh()->remote_id)->toBe('v_pub_url~v2-1.pending');
});

test('a post whose channel is gone keeps its url and still moves its publication', function () {
    $account = SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']);
    $post = tiktokPostAwaitingVideo($account);
    $publication = app(SyncTryPostPublication::class)->handle($post);
    $post->forceFill(['social_account_id' => null])->save();

    app(AssignTikTokVideoId::class)->handle($post->fresh(), '7694860629638940686');

    expect($post->fresh())
        ->platform_post_id->toBe('7694860629638940686')
        ->platform_url->toBe('https://www.tiktok.com/@tiktoker')
        ->and($publication->fresh()->remote_id)->toBe('7694860629638940686');
});
