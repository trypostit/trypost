<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Post\Origin;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\ResolveTikTokVideoId;
use App\Models\AnalyticsPublication;
use App\Models\Media;
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
        'origin' => blank($post) || $post->origin === Origin::Network ? PublicationOrigin::External : PublicationOrigin::TryPost,
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

test('a second run finds nothing left to repair', function () {
    $chain = claimedTikTokVideoChain();

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();
    $this->artisan('tiktok:repair-video-ids', ['--dry-run' => true])
        ->expectsOutputToContain('0 post(s) would get their own video back')
        ->assertSuccessful();

    expect($chain['second']->fresh()->platform_post_id)->toBe('7000000000000000002');
});

test('the copy the importer made of a post own video goes with its media', function () {
    $chain = claimedTikTokVideoChain();
    $media = Media::factory()->ownedByPost($chain['imported'])->create();

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();

    expect(Media::query()->whereKey($media->id)->exists())->toBeFalse();
});

test('a post gets a video url built from its channel when the video has no permalink', function (?string $username, string $expectedUrl) {
    $chain = claimedTikTokVideoChain();
    $chain['secondVideo']->update(['permalink' => null]);
    $this->account->update(['username' => $username]);

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();

    expect($chain['second']->fresh()->platform_url)->toBe($expectedUrl);
})->with([
    'with a username' => ['tiktoker', 'https://www.tiktok.com/@tiktoker/video/7000000000000000002'],
    'without a username' => [null, 'https://www.tiktok.com/@tiktoker'],
]);

test('posts on a channel that is not connected are not sent to TikTok', function () {
    $chain = claimedTikTokVideoChain();
    $this->account->update(['status' => Status::Disconnected]);

    $this->artisan('tiktok:repair-video-ids')
        ->expectsOutputToContain('0 post(s) are asking TikTok for their video id.')
        ->assertSuccessful();

    expect($chain['second']->fresh()->platform_post_id)->toBe('7000000000000000002');
    Queue::assertNotPushed(ResolveTikTokVideoId::class);
});

test('posts whose channel is gone are left alone', function () {
    $chain = claimedTikTokVideoChain();
    $chain['secondVideo']->update(['permalink' => null]);
    Post::query()->whereKey([$chain['first']->id, $chain['second']->id])->update(['social_account_id' => null]);

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();

    expect($chain['second']->fresh()->platform_post_id)->toBe('7000000000000000001')
        ->and($chain['firstVideo']->fresh()->post_id)->toBe($chain['second']->id);
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

test('a run of posts that each took the previous post video all get their own back', function () {
    $first = repairTikTokPost('v_pub_url~v2-1.first', '2026-10-07 10:00:30');
    repairTikTokVideo('v_pub_url~v2-1.first', '2026-10-07 10:00:30', $first);
    $second = repairTikTokPost('7000000000000000011', '2026-10-08 10:00:30');
    $firstVideo = repairTikTokVideo('7000000000000000011', '2026-10-07 10:00:00', $second);
    $third = repairTikTokPost('7000000000000000012', '2026-10-09 10:00:30');
    $secondVideo = repairTikTokVideo('7000000000000000012', '2026-10-08 10:00:00', $third);
    $thirdVideo = repairTikTokVideo('7000000000000000013', '2026-10-09 10:00:00');

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();

    expect($second->fresh()->platform_post_id)->toBe('7000000000000000012')
        ->and($third->fresh()->platform_post_id)->toBe('7000000000000000013')
        ->and($secondVideo->fresh()->post_id)->toBe($second->id)
        ->and($thirdVideo->fresh()->post_id)->toBe($third->id)
        ->and($firstVideo->fresh()->post_id)->toBeNull();

    Queue::assertPushed(ResolveTikTokVideoId::class, fn (ResolveTikTokVideoId $job): bool => $job->post->is($first));
});

test('a post is left alone when the post holding its own video cannot be repaired', function () {
    $first = repairTikTokPost('v_pub_url~v2-1.first', '2026-10-07 10:00:30');
    repairTikTokVideo('v_pub_url~v2-1.first', '2026-10-07 10:00:30', $first);
    $second = repairTikTokPost('7000000000000000021', '2026-10-08 10:00:30');
    repairTikTokVideo('7000000000000000021', '2026-10-07 10:00:00', $second);
    $third = repairTikTokPost('7000000000000000022', '2026-10-09 10:00:30');
    repairTikTokVideo('7000000000000000022', '2026-10-08 10:00:00', $third);
    $correct = repairTikTokPost('7000000000000000023', '2026-10-09 10:00:35');
    repairTikTokVideo('7000000000000000023', '2026-10-09 10:00:00', $correct);

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();

    expect($second->fresh()->platform_post_id)->toBe('7000000000000000021')
        ->and($third->fresh()->platform_post_id)->toBe('7000000000000000022')
        ->and($correct->fresh()->platform_post_id)->toBe('7000000000000000023');
});

test('posts are left alone when their own video is unknown, ambiguous or claimed by another post', function (string $case) {
    $previous = repairTikTokPost('v_pub_url~v2-1.previous', '2026-10-07 10:00:30');
    repairTikTokVideo('v_pub_url~v2-1.previous', '2026-10-07 10:00:30', $previous);
    $post = repairTikTokPost('7000000000000000031', '2026-10-08 10:00:30');
    $held = repairTikTokVideo('7000000000000000031', '2026-10-07 10:00:00', $post);
    $video = repairTikTokVideo('7000000000000000032', $case === 'unknown' ? '2026-10-08 09:50:00' : '2026-10-08 10:00:20');

    if ($case === 'ambiguous') {
        repairTikTokVideo('7000000000000000033', '2026-10-08 09:59:40');
    } elseif ($case === 'claimed') {
        $other = repairTikTokPost('v_pub_url~v2-1.other', '2026-10-07 12:00:30');
        repairTikTokVideo('v_pub_url~v2-1.other', '2026-10-07 12:00:30', $other);
        $sibling = repairTikTokPost('7000000000000000034', '2026-10-08 10:01:00');
        repairTikTokVideo('7000000000000000034', '2026-10-07 12:00:00', $sibling);
    }

    $this->artisan('tiktok:repair-video-ids')->assertSuccessful();

    expect($post->fresh()->platform_post_id)->toBe('7000000000000000031')
        ->and($held->fresh()->post_id)->toBe($post->id)
        ->and($video->fresh()->post_id)->toBeNull();
})->with([
    'no video in its window' => ['unknown'],
    'two videos in its window' => ['ambiguous'],
    'another post claims the same video' => ['claimed'],
]);
