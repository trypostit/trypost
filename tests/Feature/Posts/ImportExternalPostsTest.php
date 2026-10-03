<?php

declare(strict_types=1);

use App\Actions\Post\ImportExternalPosts;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Post\Origin;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Events\PostCreated;
use App\Events\PostStatusChanged;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Support\Social\ThreadProgress;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
    config()->set('trypost.external_posts.import_limit', 50);
    config()->set('trypost.external_posts.x_import_days', 30);
});

function externalPublication(SocialAccount $account, array $attributes = []): AnalyticsPublication
{
    return AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'provider_published_at' => now()->subDay(),
        ...$attributes,
    ]);
}

function sentByTryPost(SocialAccount $account, string $platformPostId, string $content, CarbonImmutable $publishedAt, ?ContentType $contentType = null): PostPlatform
{
    $post = Post::factory()->create([
        'workspace_id' => $account->workspace_id,
        'content' => $content,
        'status' => PostStatus::Published,
        'origin' => Origin::TryPost,
        'published_at' => $publishedAt,
    ]);

    return PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => $contentType ?? ($account->platform === Platform::TikTok ? ContentType::TikTokVideo : ContentType::defaultFor($account->platform)),
        'platform_post_id' => $platformPostId,
        'platform_url' => null,
        'published_at' => $publishedAt,
    ]);
}

test('a publication becomes a published network post linked to its analytics row', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $publication = externalPublication($account, [
        'remote_id' => 'ig-1',
        'permalink' => 'https://www.instagram.com/reel/ig-1/',
        'excerpt' => "  First line\nSecond line  ",
        'content_type' => PublicationContentType::Reel,
        'provider_published_at' => CarbonImmutable::parse('2026-09-30 08:15:00', 'UTC'),
    ]);

    $created = ImportExternalPosts::execute($account);

    $post = Post::query()->imported()->sole();
    $target = $post->postPlatforms()->sole();

    expect($created)->toBe([$post->id])
        ->and($post->origin)->toBe(Origin::Network)
        ->and($post->status)->toBe(PostStatus::Published)
        ->and($post->user_id)->toBeNull()
        ->and($post->created_via)->toBeNull()
        ->and($post->scheduled_at)->toBeNull()
        ->and($post->schedule_mode)->toBeNull()
        ->and($post->recurrence_frequency)->toBeNull()
        ->and($post->post_group_id)->not->toBeNull()
        ->and($post->content)->toBe("First line\nSecond line")
        ->and($post->media)->toBe([])
        ->and($post->published_at->toIso8601String())->toBe('2026-09-30T08:15:00+00:00')
        ->and($target->social_account_id)->toBe($account->id)
        ->and($target->platform)->toBe($account->platform)
        ->and($target->status)->toBe(PostPlatformStatus::Published)
        ->and($target->enabled)->toBeTrue()
        ->and($target->content_type)->toBe(ContentType::InstagramReel)
        ->and($target->platform_post_id)->toBe('ig-1')
        ->and($target->platform_url)->toBe('https://www.instagram.com/reel/ig-1/')
        ->and($target->published_at->toIso8601String())->toBe('2026-09-30T08:15:00+00:00')
        ->and($publication->fresh()->post_platform_id)->toBe($target->id);
});

test('an image-only publication without text imports with empty content', function () {
    $account = SocialAccount::factory()->instagram()->create();
    externalPublication($account, ['excerpt' => null]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->sole()->content)->toBe('');
});

test('only the newest publications up to the limit are imported regardless of insert order', function () {
    config()->set('trypost.external_posts.import_limit', 3);
    $account = SocialAccount::factory()->instagram()->create();

    foreach ([3, 1, 5, 2, 4] as $daysAgo) {
        externalPublication($account, ['remote_id' => "d{$daysAgo}", 'provider_published_at' => now()->subDays($daysAgo)]);
    }

    ImportExternalPosts::execute($account);

    expect(PostPlatform::query()->pluck('platform_post_id')->sort()->values()->all())->toBe(['d1', 'd2', 'd3']);
});

test('stories have their own import limit and never push feed posts out', function () {
    config()->set('trypost.external_posts.import_limit', 2);
    $account = SocialAccount::factory()->instagram()->create();

    foreach ([1, 2, 3] as $hoursAgo) {
        externalPublication($account, ['remote_id' => "story-{$hoursAgo}", 'content_type' => PublicationContentType::Story, 'provider_published_at' => now()->subHours($hoursAgo)]);
    }

    foreach ([1, 2, 3] as $daysAgo) {
        externalPublication($account, ['remote_id' => "feed-{$daysAgo}", 'content_type' => PublicationContentType::Image, 'provider_published_at' => now()->subDays($daysAgo)]);
    }

    ImportExternalPosts::execute($account);

    expect(PostPlatform::query()->pluck('platform_post_id')->sort()->values()->all())->toBe(['feed-1', 'feed-2', 'story-1', 'story-2']);
});

test('discovery imports newer posts and never removes older imports', function () {
    config()->set('trypost.external_posts.import_limit', 2);
    $account = SocialAccount::factory()->instagram()->create();
    externalPublication($account, ['remote_id' => 'old-1', 'provider_published_at' => now()->subDays(9)]);
    externalPublication($account, ['remote_id' => 'old-2', 'provider_published_at' => now()->subDays(8)]);
    ImportExternalPosts::execute($account);

    externalPublication($account, ['remote_id' => 'new-1', 'provider_published_at' => now()->subDays(2)]);
    externalPublication($account, ['remote_id' => 'new-2', 'provider_published_at' => now()->subDay()]);
    ImportExternalPosts::execute($account);

    expect(PostPlatform::query()->pluck('platform_post_id')->sort()->values()->all())->toBe(['new-1', 'new-2', 'old-1', 'old-2']);
});

test('x imports only publications inside the configured day window', function () {
    $account = SocialAccount::factory()->x()->create();
    externalPublication($account, ['remote_id' => 'recent', 'provider_published_at' => now()->subDays(10)]);
    externalPublication($account, ['remote_id' => 'stale', 'provider_published_at' => now()->subDays(40)]);

    ImportExternalPosts::execute($account);

    expect(PostPlatform::query()->pluck('platform_post_id')->all())->toBe(['recent']);
});

test('running the import twice creates each post once', function () {
    $account = SocialAccount::factory()->instagram()->create();
    externalPublication($account);

    ImportExternalPosts::execute($account);
    $second = ImportExternalPosts::execute($account);

    expect($second)->toBe([])
        ->and(Post::query()->imported()->count())->toBe(1);
});

test('a publication trypost already published is linked instead of duplicated', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = PostPlatform::factory()->instagram()->published()->create([
        'social_account_id' => $account->id,
        'platform_post_id' => 'shared-1',
    ]);
    $tryPost->post->update(['workspace_id' => $account->workspace_id]);
    $publication = externalPublication($account, ['remote_id' => 'shared-1']);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0)
        ->and($publication->fresh()->post_platform_id)->toBe($tryPost->id);
});

test('a facebook post links the trypost video it carries by video id', function () {
    $account = SocialAccount::factory()->facebook()->create();
    $tryPost = PostPlatform::factory()->facebookReel()->published()->create([
        'social_account_id' => $account->id,
        'platform_post_id' => 'video-9',
    ]);
    $tryPost->post->update(['workspace_id' => $account->workspace_id]);
    $publication = externalPublication($account, [
        'remote_id' => 'page_post-1',
        'provider_metadata' => ['video_id' => 'video-9'],
    ]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(0)
        ->and($publication->fresh()->post_platform_id)->toBe($tryPost->id);
});

test('a facebook video already tracked by its trypost publication is left unlinked', function () {
    $account = SocialAccount::factory()->facebook()->create();
    $tryPost = PostPlatform::factory()->facebookReel()->published()->create([
        'social_account_id' => $account->id,
        'platform_post_id' => 'video-9',
    ]);
    $tryPost->post->update(['workspace_id' => $account->workspace_id]);
    externalPublication($account, ['remote_id' => 'video-9', 'post_platform_id' => $tryPost->id]);
    $external = externalPublication($account, [
        'remote_id' => 'page_post-1',
        'provider_metadata' => ['video_id' => 'video-9'],
    ]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(0)
        ->and($external->fresh()->post_platform_id)->toBeNull();
});

test('importing fires no post events and queues nothing', function () {
    Event::fake([PostCreated::class, PostStatusChanged::class]);
    $account = SocialAccount::factory()->instagram()->create();
    externalPublication($account);
    Queue::fake();

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1);
    Event::assertNotDispatched(PostCreated::class);
    Event::assertNotDispatched(PostStatusChanged::class);
    Queue::assertNothingPushed();
});

test('a broken publication is reported and the rest of the page still imports', function () {
    Exceptions::fake();
    $account = SocialAccount::factory()->instagram()->create();
    $broken = externalPublication($account, ['remote_id' => 'broken', 'provider_published_at' => now()->subDay()]);
    externalPublication($account, ['remote_id' => 'fine', 'provider_published_at' => now()->subDays(2)]);
    DB::table('analytics_publications')->where('id', $broken->id)->update(['content_type' => 'not-a-type']);

    ImportExternalPosts::execute($account);

    expect(PostPlatform::query()->pluck('platform_post_id')->all())->toBe(['fine'])
        ->and(DB::table('analytics_publications')->where('id', $broken->id)->value('post_platform_id'))->toBeNull();
    Exceptions::assertReported(ValueError::class);
});

test('platforms excluded from analytics never import', function () {
    $account = SocialAccount::factory()->linkedin()->create();
    externalPublication($account);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0);
});

test('an account only imports its own workspace publications', function () {
    $ours = SocialAccount::factory()->instagram()->create();
    $theirs = SocialAccount::factory()->instagram()->create();
    externalPublication($ours, ['remote_id' => 'ours']);
    externalPublication($theirs, ['remote_id' => 'theirs']);

    ImportExternalPosts::execute($ours);

    $post = Post::query()->imported()->sole();

    expect($post->workspace_id)->toBe($ours->workspace_id)
        ->and($post->postPlatforms()->sole()->platform_post_id)->toBe('ours');
});

test('a reconnected account does not import posts that already exist', function () {
    $old = SocialAccount::factory()->instagram()->create();
    externalPublication($old, ['remote_id' => 'kept']);
    ImportExternalPosts::execute($old);
    $old->delete();

    $new = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $old->workspace_id,
        'platform_user_id' => $old->platform_user_id,
    ]);
    AnalyticsPublication::query()->where('remote_id', 'kept')->update(['social_account_id' => $new->id]);

    ImportExternalPosts::execute($new);

    expect(Post::query()->imported()->count())->toBe(1);
});

test('a dismissed publication and one older than the history retention are skipped', function () {
    config()->set('trypost.posts.history_retention_days', 730);
    $account = SocialAccount::factory()->instagram()->create();
    externalPublication($account, ['remote_id' => 'dismissed', 'post_dismissed_at' => now()]);
    externalPublication($account, ['remote_id' => 'expired', 'provider_published_at' => now()->subDays(731)]);

    expect(ImportExternalPosts::execute($account))->toBe([]);
});

test('an import limit of zero turns importing off', function () {
    config()->set('trypost.external_posts.import_limit', 0);
    $account = SocialAccount::factory()->instagram()->create();
    externalPublication($account);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0);
});

test('an instagram post trypost published under its container id is linked instead of imported', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = sentByTryPost($account, 'container-1', '<p>Launch   day</p><p>is <strong>here</strong> &amp; now</p>', now()->subHours(3)->toImmutable());
    $publication = externalPublication($account, [
        'remote_id' => 'media-1',
        'permalink' => 'https://www.instagram.com/p/media-1/',
        'excerpt' => "Launch day\nis here & now",
        'provider_published_at' => now()->subHours(3)->addMinutes(2),
    ]);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0)
        ->and($publication->fresh()->post_platform_id)->toBe($tryPost->id)
        ->and($tryPost->fresh()->platform_post_id)->toBe('media-1')
        ->and($tryPost->fresh()->platform_url)->toBe('https://www.instagram.com/p/media-1/');
});

test('a tiktok post still keyed by its publish id is reconciled into the discovered video', function () {
    $account = SocialAccount::factory()->tiktok()->create();
    $tryPost = sentByTryPost($account, 'v_pub_url~123', '<p>A long description that the network cuts short</p>', now()->subHour()->toImmutable());
    $provisional = externalPublication($account, [
        'remote_id' => 'v_pub_url~123',
        'post_platform_id' => $tryPost->id,
        'origin' => PublicationOrigin::TryPost,
        'provider_synced_at' => null,
        'excerpt' => 'A long description that the network cuts short',
    ]);
    $discovered = externalPublication($account, [
        'remote_id' => '7300000000000000001',
        'permalink' => 'https://www.tiktok.com/@trypost/video/7300000000000000001',
        'excerpt' => 'A long description that the network…',
        'content_type' => PublicationContentType::Video,
        'provider_published_at' => now()->subHour()->addMinutes(5),
    ]);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0)
        ->and(AnalyticsPublication::query()->find($discovered->id))->toBeNull()
        ->and($provisional->fresh()->remote_id)->toBe('7300000000000000001')
        ->and($provisional->fresh()->post_platform_id)->toBe($tryPost->id)
        ->and($tryPost->fresh()->platform_post_id)->toBe('7300000000000000001')
        ->and($tryPost->fresh()->platform_url)->toBe('https://www.tiktok.com/@trypost/video/7300000000000000001');
});

test('a trypost target whose id the network already confirmed is never relinked by text', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = sentByTryPost($account, 'media-0', 'Same caption', now()->subHour()->toImmutable());
    externalPublication($account, ['remote_id' => 'media-0', 'post_platform_id' => $tryPost->id, 'origin' => PublicationOrigin::TryPost]);
    externalPublication($account, ['remote_id' => 'media-2', 'excerpt' => 'Same caption', 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($tryPost->fresh()->platform_post_id)->toBe('media-0');
});

test('two trypost posts matching the same publication link neither and import nothing this run', function () {
    Log::spy();
    $account = SocialAccount::factory()->instagram()->create();
    $first = sentByTryPost($account, 'container-1', 'Same caption', now()->subHour()->toImmutable());
    $second = sentByTryPost($account, 'container-2', 'Same caption', now()->subMinutes(50)->toImmutable());
    $publication = externalPublication($account, ['remote_id' => 'media-1', 'excerpt' => 'Same caption', 'provider_published_at' => now()->subMinutes(55)]);

    expect(ImportExternalPosts::execute($account))->toBe([])
        ->and(Post::query()->imported()->count())->toBe(0)
        ->and($publication->fresh()->post_platform_id)->toBeNull()
        ->and($first->fresh()->platform_post_id)->toBe('container-1')
        ->and($second->fresh()->platform_post_id)->toBe('container-2');
    Log::shouldHaveReceived('warning')->once();
});

test('a trypost post outside the match window is not linked by text', function () {
    config()->set('trypost.external_posts.match_window_minutes', 120);
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = sentByTryPost($account, 'container-1', 'Same caption', now()->subHours(5)->toImmutable());
    externalPublication($account, ['remote_id' => 'media-1', 'excerpt' => 'Same caption', 'provider_published_at' => now()->subHours(2)->subMinute()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($tryPost->fresh()->platform_post_id)->toBe('container-1');
});

test('a trypost post with different text is not linked', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = sentByTryPost($account, 'container-1', 'Our caption', now()->subHour()->toImmutable());
    externalPublication($account, ['remote_id' => 'media-1', 'excerpt' => 'Another caption', 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($tryPost->fresh()->platform_post_id)->toBe('container-1');
});

test('a caption-less trypost story is never taken over by a caption-less native feed post', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $story = sentByTryPost($account, 'story-container', '', now()->subHour()->toImmutable(), ContentType::InstagramStory);
    externalPublication($account, ['remote_id' => 'feed-1', 'excerpt' => null, 'content_type' => PublicationContentType::Image, 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($story->fresh()->platform_post_id)->toBe('story-container');
});

test('two caption-less feed posts are not matched by their empty text', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = sentByTryPost($account, 'container-1', '', now()->subHour()->toImmutable());
    externalPublication($account, ['remote_id' => 'media-1', 'excerpt' => null, 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($tryPost->fresh()->platform_post_id)->toBe('container-1');
});

test('a trypost story is not taken over by a native feed post with the same caption', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $story = sentByTryPost($account, 'story-container', 'Same caption', now()->subHour()->toImmutable(), ContentType::InstagramStory);
    externalPublication($account, ['remote_id' => 'feed-1', 'excerpt' => 'Same caption', 'content_type' => PublicationContentType::Image, 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($story->fresh()->platform_post_id)->toBe('story-container');
});

test('an x post not yet reported is never taken over by a native duplicate', function () {
    $account = SocialAccount::factory()->x()->create();
    $tryPost = sentByTryPost($account, 'tweet-1', 'Same words on x', now()->subHour()->toImmutable());
    externalPublication($account, ['remote_id' => 'tweet-2', 'excerpt' => 'Same words on x', 'content_type' => PublicationContentType::Text, 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($tryPost->fresh()->platform_post_id)->toBe('tweet-1');
});

test('a native post whose text is only the start of a trypost caption is not a match', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = sentByTryPost($account, 'container-1', '🔥 big launch today for everyone who waited', now()->subHour()->toImmutable());
    externalPublication($account, ['remote_id' => 'media-1', 'excerpt' => '🔥', 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($tryPost->fresh()->platform_post_id)->toBe('container-1');
});

test('a truncated network text shorter than twenty characters is not a match', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = sentByTryPost($account, 'container-1', '🔥 big launch today for everyone who waited', now()->subHour()->toImmutable());
    externalPublication($account, ['remote_id' => 'media-1', 'excerpt' => '🔥 big launch…', 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($tryPost->fresh()->platform_post_id)->toBe('container-1');
});

test('an ambiguous match is logged once a day, not on every run', function () {
    Log::spy();
    $account = SocialAccount::factory()->instagram()->create();
    sentByTryPost($account, 'container-1', 'Same caption', now()->subHour()->toImmutable());
    sentByTryPost($account, 'container-2', 'Same caption', now()->subMinutes(50)->toImmutable());
    externalPublication($account, ['remote_id' => 'media-1', 'excerpt' => 'Same caption', 'provider_published_at' => now()->subMinutes(55)]);

    ImportExternalPosts::execute($account);
    ImportExternalPosts::execute($account);
    Log::shouldHaveReceived('warning')->once();

    $this->travel(25)->hours();
    ImportExternalPosts::execute($account);
    Log::shouldHaveReceived('warning')->twice();
});

test('merging into the provisional trypost row keeps the network text and publish time', function () {
    $account = SocialAccount::factory()->tiktok()->create();
    $tryPost = sentByTryPost($account, 'v_pub_url~9', 'A caption that is long enough to match', now()->subHour()->toImmutable());
    $provisional = externalPublication($account, [
        'remote_id' => 'v_pub_url~9',
        'post_platform_id' => $tryPost->id,
        'origin' => PublicationOrigin::TryPost,
        'provider_synced_at' => null,
        'excerpt' => 'stale excerpt',
        'provider_published_at' => now()->subHour(),
    ]);
    externalPublication($account, [
        'remote_id' => '7300000000000000009',
        'excerpt' => 'A caption that is long enough to match',
        'content_type' => PublicationContentType::Video,
        'provider_published_at' => now()->subMinutes(40),
    ]);

    ImportExternalPosts::execute($account);

    expect($provisional->fresh()->remote_id)->toBe('7300000000000000009')
        ->and($provisional->fresh()->excerpt)->toBe('A caption that is long enough to match')
        ->and($provisional->fresh()->provider_published_at->toIso8601String())->toBe(now()->subMinutes(40)->toIso8601String());
});

test('a trypost feed video that instagram reports as a reel is linked', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $tryPost = sentByTryPost($account, 'container-1', 'Our feed video caption', now()->subHour()->toImmutable(), ContentType::InstagramFeed);
    $publication = externalPublication($account, ['remote_id' => 'reel-1', 'excerpt' => 'Our feed video caption', 'content_type' => PublicationContentType::Reel, 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(0)
        ->and($publication->fresh()->post_platform_id)->toBe($tryPost->id)
        ->and($tryPost->fresh()->platform_post_id)->toBe('reel-1');
});

test('a trypost story is not taken over by a native reel with the same caption', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $story = sentByTryPost($account, 'story-container', 'Same caption', now()->subHour()->toImmutable(), ContentType::InstagramStory);
    externalPublication($account, ['remote_id' => 'reel-1', 'excerpt' => 'Same caption', 'content_type' => PublicationContentType::Reel, 'provider_published_at' => now()->subHour()]);

    ImportExternalPosts::execute($account);

    expect(Post::query()->imported()->count())->toBe(1)
        ->and($story->fresh()->platform_post_id)->toBe('story-container');
});

test('our own thread replies are never imported as external posts', function () {
    $account = SocialAccount::factory()->mastodon()->create();
    $root = sentByTryPost($account, 'root-1', 'Root', now()->subDay()->toImmutable(), ContentType::MastodonPost);
    $root->update(['thread_reply_ids' => ['reply-1', 'reply-2']]);
    externalPublication($account, ['remote_id' => 'reply-1', 'excerpt' => 'Second', 'content_type' => PublicationContentType::Text]);
    externalPublication($account, ['remote_id' => 'other-1', 'excerpt' => 'Unrelated', 'content_type' => PublicationContentType::Text]);

    ImportExternalPosts::execute($account);

    expect(AnalyticsPublication::where('remote_id', 'reply-1')->value('post_platform_id'))->toBeNull()
        ->and(PostPlatform::where('platform_post_id', 'reply-1')->exists())->toBeFalse()
        ->and(PostPlatform::where('platform_post_id', 'other-1')->exists())->toBeTrue();
});

test('the live part of a thread that failed midway is never imported as external posts', function () {
    $account = SocialAccount::factory()->bluesky()->create();
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id, 'status' => PostStatus::Failed]);
    PostPlatform::factory()->failed()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => ContentType::BlueskyPost,
        'error_context' => ['category' => 'rate_limit', ThreadProgress::KEY => [
            ['hash' => 'h0', 'id' => 'root-1', 'uri' => 'at://did/app.bsky.feed.post/root-1', 'cid' => 'c1'],
            ['hash' => 'h1', 'id' => 'reply-1', 'uri' => 'at://did/app.bsky.feed.post/reply-1', 'cid' => 'c2'],
        ]],
    ]);
    externalPublication($account, ['remote_id' => 'root-1', 'excerpt' => 'Root', 'content_type' => PublicationContentType::Text]);
    externalPublication($account, ['remote_id' => 'reply-1', 'excerpt' => 'Second', 'content_type' => PublicationContentType::Text]);
    externalPublication($account, ['remote_id' => 'other-1', 'excerpt' => 'Unrelated', 'content_type' => PublicationContentType::Text]);

    ImportExternalPosts::execute($account);

    expect(Post::where('origin', Origin::Network)->count())->toBe(1)
        ->and(PostPlatform::where('platform_post_id', 'other-1')->exists())->toBeTrue()
        ->and(AnalyticsPublication::whereIn('remote_id', ['root-1', 'reply-1'])->whereNotNull('post_platform_id')->exists())->toBeFalse();
});
