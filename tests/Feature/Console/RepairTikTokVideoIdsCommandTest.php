<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Post\Origin;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\ResolveTikTokVideoId;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

    $this->account = SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']);
});

function repairTikTokPost(string $platformPostId, string $publishedAt): Post
{
    return Post::factory()->forAccount(test()->account, ContentType::TikTokVideo)->published()->create([
        'platform_post_id' => $platformPostId,
        'platform_url' => 'https://www.tiktok.com/@tiktoker',
        'published_at' => $publishedAt,
        'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value],
    ]);
}

function repairTikTokVideo(string $remoteId, string $createdAt, ?Post $post = null): AnalyticsPublication
{
    $account = test()->account;

    return AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => Platform::TikTok->network(),
        'platform' => Platform::TikTok,
        'platform_user_id' => $account->platform_user_id,
        'content_type' => PublicationContentType::Video,
        'remote_id' => $remoteId,
        'permalink' => "https://www.tiktok.com/@tiktoker/video/{$remoteId}",
        'provider_published_at' => $createdAt,
        'post_id' => $post?->id,
        'origin' => $post === null || $post->origin === Origin::Network ? PublicationOrigin::External : PublicationOrigin::TryPost,
    ]);
}

/**
 * Post A was published before TikTok reported its video, and post B, a day
 * later, took A's video by caption while its own video was imported apart.
 *
 * @return array{first: Post, second: Post, firstVideo: AnalyticsPublication, secondVideo: AnalyticsPublication, imported: Post, provisional: AnalyticsPublication}
 */
function claimedTikTokVideoChain(): array
{
    $first = repairTikTokPost('v_pub_url~v2-1.first', '2026-10-08 10:00:30');
    $provisional = repairTikTokVideo('v_pub_url~v2-1.first', '2026-10-08 10:00:30', $first);
    $second = repairTikTokPost('7000000000000000001', '2026-10-09 10:00:30');
    $firstVideo = repairTikTokVideo('7000000000000000001', '2026-10-08 10:00:00', $second);
    $imported = Post::factory()->forAccount(test()->account, ContentType::TikTokVideo)->imported()->create([
        'platform_post_id' => '7000000000000000002',
    ]);
    $secondVideo = repairTikTokVideo('7000000000000000002', '2026-10-09 10:00:00', $imported);

    return compact('first', 'second', 'firstVideo', 'secondVideo', 'imported', 'provisional');
}

test('a post gets its own video back and the video it took is freed for the post that published it', function () {
    $chain = claimedTikTokVideoChain();

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();

    expect($chain['second']->fresh())
        ->platform_post_id->toBe('7000000000000000002')
        ->platform_url->toBe('https://www.tiktok.com/@tiktoker/video/7000000000000000002')
        ->and($chain['secondVideo']->fresh())
        ->post_id->toBe($chain['second']->id)
        ->origin->toBe(PublicationOrigin::TryPost)
        ->and($chain['firstVideo']->fresh())
        ->post_id->toBeNull()
        ->origin->toBe(PublicationOrigin::External)
        ->and(Post::query()->whereKey($chain['imported']->id)->exists())->toBeFalse()
        ->and($chain['first']->fresh()->platform_post_id)->toBe('v_pub_url~v2-1.first');

    Queue::assertPushed(ResolveTikTokVideoId::class, fn (ResolveTikTokVideoId $job): bool => $job->post->is($chain['first']));
});

test('a post whose publish only finished long after TikTok created its video keeps that video', function () {
    $slow = repairTikTokPost('7000000000000000003', '2026-10-09 14:40:00');
    $video = repairTikTokVideo('7000000000000000003', '2026-10-09 14:00:00', $slow);

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();

    expect($slow->fresh()->platform_post_id)->toBe('7000000000000000003')
        ->and($video->fresh()->post_id)->toBe($slow->id);
});

test('a dry run lists the repairs without writing them', function () {
    $chain = claimedTikTokVideoChain();

    $this->artisan('tiktok:repair-video-ids', ['--dry-run' => true])
        ->expectsOutputToContain('1 post(s) would get their own video back; 1 post(s) would ask TikTok for their video id.')
        ->assertSuccessful();

    expect($chain['second']->fresh()->platform_post_id)->toBe('7000000000000000001')
        ->and($chain['firstVideo']->fresh()->post_id)->toBe($chain['second']->id)
        ->and(Post::query()->whereKey($chain['imported']->id)->exists())->toBeTrue();

    Queue::assertNotPushed(ResolveTikTokVideoId::class);
});
