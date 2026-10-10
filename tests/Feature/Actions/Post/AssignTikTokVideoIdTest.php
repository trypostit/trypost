<?php

declare(strict_types=1);

use App\Actions\Analytics\SyncTryPostPublication;
use App\Actions\Post\AssignTikTokVideoId;
use App\Enums\PostPlatform\ContentType;
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

test('a post without an analytics publication gets the video id and its url', function () {
    $post = tiktokPostAwaitingVideo(SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']));

    app(AssignTikTokVideoId::class)->handle($post, '7694860629638940686');

    expect($post->fresh())
        ->platform_post_id->toBe('7694860629638940686')
        ->platform_url->toBe('https://www.tiktok.com/@tiktoker/video/7694860629638940686');
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
