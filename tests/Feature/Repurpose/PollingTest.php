<?php

declare(strict_types=1);

use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\SourceFormat;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Repurpose\PollRepurposeSource;
use App\Jobs\Repurpose\ProcessRepurposeItem;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Repurpose\SourceFetcherFactory;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function fakeInstagramMedia(array $rows): void
{
    Http::fake([config('trypost.platforms.instagram.graph_api').'/*' => Http::response(['data' => $rows])]);
}

function mediaRow(string $id = 'm1', string $productType = 'REELS', ?string $url = 'https://cdn.example.com/v.mp4'): array
{
    return array_filter([
        'id' => $id,
        'media_type' => 'VIDEO',
        'media_product_type' => $productType,
        'media_url' => $url,
        'caption' => 'Hi',
        'permalink' => 'https://instagram.com/p/1',
        'timestamp' => '2026-09-04T10:00:00+0000',
    ], fn ($value) => $value !== null);
}

function instagramAccount(): SocialAccount
{
    $workspace = Workspace::factory()->create();

    return SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);
}

function activeRepurposeOn(SocialAccount $account, SourceFormat $format = SourceFormat::Reel): Repurpose
{
    return Repurpose::factory()->active()->create([
        'workspace_id' => $account->workspace_id,
        'source_social_account_id' => $account->id,
        'source_format' => $format,
        'activated_at' => now()->subYear(),
    ]);
}

function poll(SocialAccount $account): void
{
    (new PollRepurposeSource($account))->handle(app(SourceFetcherFactory::class));
}

test('a new video of the watched format creates a pending item and dispatches processing', function () {
    Bus::fake();
    fakeInstagramMedia([mediaRow()]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);

    poll($account);

    $item = $repurpose->items()->sole();

    expect($item->status)->toBe(ItemStatus::Pending)
        ->and($item->source_media_id)->toBe('m1')
        ->and($repurpose->fresh()->last_polled_at)->not->toBeNull()
        ->and($repurpose->fresh()->next_poll_at)->not->toBeNull();

    Bus::assertDispatched(ProcessRepurposeItem::class);
});

test('a format the repurpose does not watch is ignored entirely', function () {
    Bus::fake();
    fakeInstagramMedia([mediaRow('feed-1', 'FEED')]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account, SourceFormat::Reel);

    poll($account);

    expect($repurpose->items()->count())->toBe(0);

    Bus::assertNotDispatched(ProcessRepurposeItem::class);
});

test('two repurposes on one account share a single round of calls', function () {
    Bus::fake();
    fakeInstagramMedia([mediaRow('r1', 'REELS'), mediaRow('f1', 'FEED')]);

    $account = instagramAccount();
    $reels = activeRepurposeOn($account, SourceFormat::Reel);
    $videos = activeRepurposeOn($account, SourceFormat::Video);

    poll($account);

    Http::assertSentCount(1);

    expect($reels->items()->sole()->source_media_id)->toBe('r1')
        ->and($videos->items()->sole()->source_media_id)->toBe('f1');

    Bus::assertDispatchedTimes(ProcessRepurposeItem::class, 2);
});

test('a video without a download url is skipped', function () {
    Bus::fake();
    fakeInstagramMedia([mediaRow('m3', 'REELS', null)]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);

    poll($account);

    expect($repurpose->items()->sole()->reason)->toBe(ItemReason::MediaUrlMissing);

    Bus::assertNotDispatched(ProcessRepurposeItem::class);
});

test('media already published through trypost is skipped', function () {
    Bus::fake();
    fakeInstagramMedia([mediaRow('known-1')]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);

    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    PostPlatform::factory()->for($post)->create(['platform_post_id' => 'known-1']);

    poll($account);

    expect($repurpose->items()->sole()->reason)->toBe(ItemReason::PublishedViaTrypost);

    Bus::assertNotDispatched(ProcessRepurposeItem::class);
});

test('another workspace publishing the same media id does not skip ours', function () {
    Bus::fake();
    fakeInstagramMedia([mediaRow('known-1')]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);

    $post = Post::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    PostPlatform::factory()->for($post)->create(['platform_post_id' => 'known-1']);

    poll($account);

    expect($repurpose->items()->sole()->reason)->toBeNull();

    Bus::assertDispatched(ProcessRepurposeItem::class);
});

test('media published before the watermark is ignored', function () {
    Bus::fake();
    fakeInstagramMedia([mediaRow()]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);
    $repurpose->update(['activated_at' => now()]);

    poll($account);

    expect($repurpose->items()->count())->toBe(0);
});

test('polling twice logs the same media once', function () {
    Bus::fake();
    fakeInstagramMedia([mediaRow()]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);

    poll($account);
    poll($account->fresh());

    expect($repurpose->items()->count())->toBe(1);

    Bus::assertDispatchedTimes(ProcessRepurposeItem::class, 1);
});

test('an api error is recorded on every repurpose of the account', function () {
    Bus::fake();
    Http::fake([config('trypost.platforms.instagram.graph_api').'/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

    $account = instagramAccount();
    $first = activeRepurposeOn($account, SourceFormat::Reel);
    $second = activeRepurposeOn($account, SourceFormat::Video);

    poll($account);

    expect($first->fresh()->last_error)->toContain('Invalid token')
        ->and($second->fresh()->last_error)->toContain('Invalid token')
        ->and($first->items()->count())->toBe(0);
});

test('a rate limited source backs off instead of retrying next tick', function () {
    Bus::fake();
    config()->set('trypost.repurpose.backoff_minutes', 60);
    config()->set('trypost.repurpose.poll_interval_minutes', 15);
    Http::fake([config('trypost.platforms.instagram.graph_api').'/*' => Http::response(['error' => ['code' => 4, 'message' => 'Application request limit reached']], 400)]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);

    poll($account);

    $repurpose = $repurpose->fresh();

    expect($repurpose->status)->toBe(Status::Active)
        ->and(now()->diffInMinutes($repurpose->next_poll_at, absolute: true))->toBeGreaterThan(30);
});

test('a disconnected source is not polled', function () {
    Bus::fake();
    Http::fake();

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);
    $account->update(['disconnected_at' => now()]);

    poll($account->fresh());

    Http::assertNothingSent();
    expect($repurpose->items()->count())->toBe(0);
});

test('the command dispatches one job per due account, not per repurpose', function () {
    Bus::fake();

    $account = instagramAccount();
    activeRepurposeOn($account, SourceFormat::Reel);
    activeRepurposeOn($account, SourceFormat::Video);

    Repurpose::factory()->create();
    Repurpose::factory()->paused()->create();
    Repurpose::factory()->disabled()->create();

    $this->artisan('repurpose:poll')->assertSuccessful();

    Bus::assertDispatchedTimes(PollRepurposeSource::class, 1);
});

test('an account that is not due yet is not dispatched', function () {
    Bus::fake();

    $account = instagramAccount();
    activeRepurposeOn($account)->update(['next_poll_at' => now()->addMinutes(10)]);

    $this->artisan('repurpose:poll')->assertSuccessful();

    Bus::assertNotDispatched(PollRepurposeSource::class);
});

test('a business rate limit backs off even without the english wording', function () {
    Bus::fake();
    config()->set('trypost.repurpose.backoff_minutes', 60);
    config()->set('trypost.repurpose.poll_interval_minutes', 15);

    Http::fake([config('trypost.platforms.instagram.graph_api').'/*' => Http::response([
        'error' => ['code' => 80002, 'message' => 'There have been too many calls from this Instagram Business Account'],
    ], 400)]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);

    poll($account);

    expect(now()->diffInMinutes($repurpose->fresh()->next_poll_at, absolute: true))->toBeGreaterThan(30);
});

test('a token echoed back by the source never lands in the stored error', function () {
    Bus::fake();
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/*' => Http::response(
            'Invalid OAuth request: access_token=EAAG-super-secret',
            400,
        ),
    ]);

    $account = instagramAccount();
    $repurpose = activeRepurposeOn($account);

    poll($account);

    expect($repurpose->fresh()->last_error)
        ->toContain('[REDACTED]')
        ->not->toContain('EAAG-super-secret');
});

test('a skipped poll reschedules without erasing the recorded error', function () {
    $workspace = Workspace::factory()->create();
    $source = SocialAccount::factory()->for($workspace)->create([
        'platform' => Platform::Instagram,
        'is_active' => false,
    ]);

    $repurpose = Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $source->id,
        'last_error' => 'Instagram rejected the request',
        'next_poll_at' => now()->subHour(),
    ]);

    (new PollRepurposeSource($source))->handle(app(SourceFetcherFactory::class));

    $fresh = $repurpose->fresh();

    // The error is what tells the user why it stopped, so it survives. The
    // schedule still moves, or the scheduler re-dispatches this on every tick.
    expect($fresh->last_error)->toBe('Instagram rejected the request')
        ->and($fresh->next_poll_at->isFuture())->toBeTrue();
});
