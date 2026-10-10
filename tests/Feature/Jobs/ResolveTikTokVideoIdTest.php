<?php

declare(strict_types=1);

use App\Actions\Analytics\SyncTryPostPublication;
use App\Console\Commands\ResolveTikTokVideoIds;
use App\Enums\Post\Origin;
use App\Enums\PostPlatform\ContentType;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\ResolveTikTokVideoId;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-10 12:00:00', 'UTC'));

    $this->account = SocialAccount::factory()->tiktok()->create([
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDay(),
    ]);
    $this->statusUrl = config('trypost.platforms.tiktok.api').'/post/publish/status/fetch/';
});

/**
 * @param  array<string, mixed>  $attributes
 */
function awaitingTikTokPost(array $attributes = []): Post
{
    return Post::factory()->forAccount(test()->account, ContentType::TikTokVideo)->published()->create([
        'platform_post_id' => 'v_pub_url~v2-1.pending',
        'platform_url' => 'https://www.tiktok.com/@tiktoker',
        'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value],
        ...$attributes,
    ]);
}

function resolveTikTokVideoId(Post $post): void
{
    app()->call([new ResolveTikTokVideoId($post), 'handle']);
}

test('the video id TikTok reports replaces the publish id on the post and its analytics publication', function () {
    $post = awaitingTikTokPost();
    $publication = app(SyncTryPostPublication::class)->handle($post);

    Http::fake([$this->statusUrl => Http::response([
        'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7694860629638940686']],
        'error' => ['code' => 'ok'],
    ])]);

    resolveTikTokVideoId($post);

    expect($post->fresh())
        ->platform_post_id->toBe('7694860629638940686')
        ->platform_url->toBe('https://www.tiktok.com/@tiktoker/video/7694860629638940686')
        ->and($publication->fresh()->remote_id)->toBe('7694860629638940686');

    Http::assertSent(fn ($request) => $request['publish_id'] === 'v_pub_url~v2-1.pending');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/video/list/'));
});

test('a post TikTok has not reported yet keeps its publish id and records the check', function () {
    $post = awaitingTikTokPost();

    Http::fake([$this->statusUrl => Http::response([
        'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []],
        'error' => ['code' => 'ok'],
    ])]);

    resolveTikTokVideoId($post);

    expect($post->fresh())
        ->platform_post_id->toBe('v_pub_url~v2-1.pending')
        ->platform_url->toBe('https://www.tiktok.com/@tiktoker')
        ->last_reconciled_at->not->toBeNull();

    Http::assertSentCount(1);
});

test('posts that cannot get a video id are never sent to TikTok', function (array $attributes) {
    Http::fake();

    resolveTikTokVideoId(awaitingTikTokPost($attributes));

    Http::assertNothingSent();
})->with([
    'self only' => [['meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value]]],
    'already resolved' => [['platform_post_id' => '7694860629638940686']],
    'imported' => [['origin' => Origin::Network]],
]);

test('the sweep asks for a new post on every run, then hourly, then daily, for a month', function (int $publishedMinutesAgo, ?int $checkedMinutesAgo, bool $dispatched) {
    $post = awaitingTikTokPost(['published_at' => now()->subMinutes($publishedMinutesAgo)]);
    $post->forceFill([
        'last_reconciled_at' => $checkedMinutesAgo === null ? null : now()->subMinutes($checkedMinutesAgo),
    ])->save();

    $this->artisan(ResolveTikTokVideoIds::class)->assertSuccessful();

    $dispatched
        ? Queue::assertPushed(ResolveTikTokVideoId::class, fn (ResolveTikTokVideoId $job): bool => $job->post->is($post))
        : Queue::assertNotPushed(ResolveTikTokVideoId::class);
})->with([
    'never checked' => [2, null, true],
    'new, checked 3 minutes ago' => [30, 3, false],
    'new, checked 5 minutes ago' => [30, 5, true],
    'first day, checked 30 minutes ago' => [120, 30, false],
    'first day, checked an hour ago' => [120, 60, true],
    'older, checked 2 hours ago' => [3 * 1440, 120, false],
    'older, checked a day ago' => [3 * 1440, 1440, true],
    'past the month' => [31 * 1440, null, false],
]);

test('the sweep skips posts that already have their video id or never get one', function (array $attributes) {
    awaitingTikTokPost($attributes);

    $this->artisan(ResolveTikTokVideoIds::class)->assertSuccessful();

    Queue::assertNotPushed(ResolveTikTokVideoId::class);
})->with([
    'resolved' => [['platform_post_id' => '7694860629638940686']],
    'self only' => [['meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value]]],
]);
