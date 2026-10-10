<?php

declare(strict_types=1);

use App\Actions\Analytics\SyncTryPostPublication;
use App\Actions\Post\AssignTikTokVideoId;
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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PDOException;

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

test('a video held by another post is logged once a day, records the check and leaves the post as it is', function () {
    Log::spy();
    $post = awaitingTikTokPost(['published_at' => now()->subMinutes(30)]);
    app(SyncTryPostPublication::class)->handle($post);
    app(SyncTryPostPublication::class)->handle(awaitingTikTokPost(['platform_post_id' => '7694860629638940686']));

    Http::fake([$this->statusUrl => Http::response([
        'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7694860629638940686']],
        'error' => ['code' => 'ok'],
    ])]);

    resolveTikTokVideoId($post);
    resolveTikTokVideoId($post);

    Log::shouldHaveReceived('warning')
        ->with('TikTok reported a video another TryPost post holds; not assigned.', Mockery::type('array'))
        ->once();

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
    'followers only' => [['meta' => ['privacy_level' => PrivacyLevel::FollowerOfCreator->value]]],
    'friends only' => [['meta' => ['privacy_level' => PrivacyLevel::MutualFollowFriends->value]]],
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

test('a video discovered at the same moment leaves the post for the next check', function () {
    $post = awaitingTikTokPost();
    $assignVideoId = Mockery::mock(AssignTikTokVideoId::class);
    $assignVideoId->shouldReceive('handle')->once()->andThrow(new UniqueConstraintViolationException('pgsql', 'insert', [], new PDOException('duplicate key value')));
    $this->app->instance(AssignTikTokVideoId::class, $assignVideoId);

    Http::fake([$this->statusUrl => Http::response([
        'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['7694860629638940686']],
        'error' => ['code' => 'ok'],
    ])]);

    resolveTikTokVideoId($post);

    expect($post->fresh())
        ->platform_post_id->toBe('v_pub_url~v2-1.pending')
        ->last_reconciled_at->not->toBeNull();
});

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

test('the sweep asks again for a new post on every run, then hourly, then daily, for a month', function (int $publishedMinutesAgo, ?int $checkedMinutesAgo, bool $dispatched) {
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
    'new, checked 9 minutes ago' => [30, 9, false],
    'new, checked 10 minutes ago' => [30, 10, true],
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
    'followers only' => [['meta' => ['privacy_level' => PrivacyLevel::FollowerOfCreator->value]]],
    'imported' => [['origin' => Origin::Network]],
    'publish failed' => [['publish_status' => PublishStatus::Failed]],
    'publish retrying' => [['publish_status' => PublishStatus::Retrying]],
]);

test('a post is checked once while its check is already queued', function () {
    $post = awaitingTikTokPost();

    ResolveTikTokVideoId::dispatch($post)->delay(now()->addSeconds(ResolveTikTokVideoId::FIRST_CHECK_AFTER_SECONDS));
    $this->artisan(ResolveTikTokVideoIds::class)->assertSuccessful();

    Queue::assertPushed(ResolveTikTokVideoId::class, 1);
});

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

test('the sweep runs every fifteen minutes on one server', function () {
    $event = collect(app(Schedule::class)->events())
        ->sole(fn (Event $event): bool => str_contains((string) $event->command, 'social:resolve-tiktok-video-ids'));

    foreach (['12:00' => true, '12:15' => true, '12:45' => true, '12:01' => false, '12:14' => false] as $time => $due) {
        $this->travelTo(CarbonImmutable::parse("2026-10-10 {$time}:00", 'UTC'));

        expect($event->isDue(app()))->toBe($due, "at {$time}");
    }

    expect($event->onOneServer)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});

test('a sweep with no post waiting for its video id sends nothing to TikTok', function () {
    awaitingTikTokPost(['platform_post_id' => '7694860629638940686']);
    Http::fake();

    $this->artisan(ResolveTikTokVideoIds::class)->assertSuccessful();

    Queue::assertNotPushed(ResolveTikTokVideoId::class);
    Http::assertNothingSent();
});
