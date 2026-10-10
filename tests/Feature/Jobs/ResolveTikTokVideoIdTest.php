<?php

declare(strict_types=1);

use App\Actions\Analytics\SyncTryPostPublication;
use App\Console\Commands\ResolveTikTokVideoIds;
use App\Enums\Post\Origin;
use App\Enums\Post\PublishStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Enums\TikTok\PrivacyLevel;
use App\Jobs\ResolveTikTokVideoId;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Queue\SyncQueue;
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

test('a video held by another post records the check so the sweep waits before asking again', function () {
    $post = awaitingTikTokPost(['published_at' => now()->subMinutes(30)]);
    app(SyncTryPostPublication::class)->handle($post);
    app(SyncTryPostPublication::class)->handle(awaitingTikTokPost(['platform_post_id' => '7694860629638940686']));

    Http::fake([$this->statusUrl => Http::response([
        'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7694860629638940686']],
        'error' => ['code' => 'ok'],
    ])]);

    expect(fn () => resolveTikTokVideoId($post))->toThrow(LogicException::class);

    expect($post->fresh())
        ->platform_post_id->toBe('v_pub_url~v2-1.pending')
        ->last_reconciled_at->not->toBeNull();

    $this->artisan(ResolveTikTokVideoIds::class)->assertSuccessful();

    Queue::assertNotPushed(ResolveTikTokVideoId::class);
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

test('a post on a channel that is not connected is never sent to TikTok', function (Status $status) {
    $post = awaitingTikTokPost();
    $this->account->update(['status' => $status]);
    Http::fake();

    resolveTikTokVideoId($post);

    Http::assertNothingSent();
})->with([Status::Disconnected, Status::TokenExpired]);

test('a post deleted before its check runs drops the job without failing it', function () {
    $post = awaitingTikTokPost();
    $job = new ResolveTikTokVideoId($post);
    $post->delete();
    Http::fake();

    $queue = new SyncQueue;
    $queue->setContainer(app());
    $queue->push($job);

    Http::assertNothingSent();
});

test('the sweep asks for a new post on every run, then hourly, then daily, for a month', function (int $publishedMinutesAgo, ?int $checkedMinutesAgo, bool $dispatched) {
    $post = awaitingTikTokPost(['published_at' => now()->subMinutes($publishedMinutesAgo)]);
    $post->forceFill([
        'last_reconciled_at' => transform($checkedMinutesAgo, fn (int $minutes): CarbonImmutable => now()->subMinutes($minutes)),
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
    'imported' => [['origin' => Origin::Network]],
    'publish failed' => [['publish_status' => PublishStatus::Failed]],
    'publish retrying' => [['publish_status' => PublishStatus::Retrying]],
]);

test('the sweep queues the check on the TikTok queue', function () {
    awaitingTikTokPost();

    $this->artisan(ResolveTikTokVideoIds::class)->assertSuccessful();

    Queue::assertPushedOn(Platform::TikTok->queue(), ResolveTikTokVideoId::class);
});

test('the sweep skips posts whose channel is not connected', function (Status $status) {
    awaitingTikTokPost();
    $this->account->update(['status' => $status]);

    $this->artisan(ResolveTikTokVideoIds::class)->assertSuccessful();

    Queue::assertNotPushed(ResolveTikTokVideoId::class);
})->with([Status::Disconnected, Status::TokenExpired]);

test('the sweep runs every minute on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->sole(fn (Event $event): bool => str_contains((string) $event->command, 'social:resolve-tiktok-video-ids'));

    expect($event->expression)->toBe('* * * * *')
        ->and($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('a sweep with no post waiting for its video id sends nothing to TikTok', function () {
    awaitingTikTokPost(['platform_post_id' => '7694860629638940686']);
    Http::fake();

    $this->artisan(ResolveTikTokVideoIds::class)->assertSuccessful();

    Queue::assertNotPushed(ResolveTikTokVideoId::class);
    Http::assertNothingSent();
});
