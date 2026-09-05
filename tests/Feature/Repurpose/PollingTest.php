<?php

declare(strict_types=1);

use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
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

function instagramVideoRow(string $id = 'm1', ?string $url = 'https://cdn.example.com/v.mp4'): array
{
    return array_filter([
        'id' => $id,
        'media_type' => 'VIDEO',
        'media_url' => $url,
        'caption' => 'Hi',
        'permalink' => 'https://instagram.com/p/1',
        'timestamp' => '2026-09-04T10:00:00+0000',
    ], fn ($value) => $value !== null);
}

function activeRepurpose(): Repurpose
{
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Instagram]);

    return Repurpose::factory()->active()->create([
        'workspace_id' => $workspace->id,
        'source_social_account_id' => $account->id,
    ]);
}

function poll(Repurpose $repurpose): void
{
    (new PollRepurposeSource($repurpose))->handle(app(SourceFetcherFactory::class));
}

test('a new video creates a pending item and dispatches processing', function () {
    Bus::fake();
    fakeInstagramMedia([instagramVideoRow()]);

    $repurpose = activeRepurpose();
    poll($repurpose);

    $item = $repurpose->items()->sole();

    expect($item->status)->toBe(ItemStatus::Pending)
        ->and($item->source_media_id)->toBe('m1')
        ->and($item->source_permalink)->toBe('https://instagram.com/p/1')
        ->and($repurpose->fresh()->last_polled_at)->not->toBeNull()
        ->and($repurpose->fresh()->next_poll_at)->not->toBeNull();

    Bus::assertDispatched(ProcessRepurposeItem::class);
});

test('an image is skipped as not a video', function () {
    Bus::fake();
    fakeInstagramMedia([['id' => 'm2', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example.com/i.jpg', 'caption' => '', 'timestamp' => '2026-09-04T10:00:00+0000']]);

    $repurpose = activeRepurpose();
    poll($repurpose);

    $item = $repurpose->items()->sole();

    expect($item->status)->toBe(ItemStatus::Skipped)
        ->and($item->reason)->toBe(ItemReason::NotVideo);

    Bus::assertNotDispatched(ProcessRepurposeItem::class);
});

test('a video without a download url is skipped', function () {
    Bus::fake();
    fakeInstagramMedia([instagramVideoRow('m3', null)]);

    $repurpose = activeRepurpose();
    poll($repurpose);

    expect($repurpose->items()->sole()->reason)->toBe(ItemReason::MediaUrlMissing);

    Bus::assertNotDispatched(ProcessRepurposeItem::class);
});

test('media already published through trypost is skipped', function () {
    Bus::fake();
    fakeInstagramMedia([instagramVideoRow('known-1')]);

    $repurpose = activeRepurpose();
    $post = Post::factory()->create(['workspace_id' => $repurpose->workspace_id]);
    PostPlatform::factory()->for($post)->create(['platform_post_id' => 'known-1']);

    poll($repurpose);

    expect($repurpose->items()->sole()->reason)->toBe(ItemReason::PublishedViaTrypost);

    Bus::assertNotDispatched(ProcessRepurposeItem::class);
});

test('media published by another workspace does not count as ours', function () {
    Bus::fake();
    fakeInstagramMedia([instagramVideoRow('known-2')]);

    $repurpose = activeRepurpose();
    $foreignPost = Post::factory()->create();
    PostPlatform::factory()->for($foreignPost)->create(['platform_post_id' => 'known-2']);

    poll($repurpose);

    expect($repurpose->items()->sole()->status)->toBe(ItemStatus::Pending);

    Bus::assertDispatched(ProcessRepurposeItem::class);
});

test('polling twice logs the same media once', function () {
    Bus::fake();
    fakeInstagramMedia([instagramVideoRow()]);

    $repurpose = activeRepurpose();
    poll($repurpose);
    poll($repurpose->fresh());

    expect($repurpose->items()->count())->toBe(1);

    Bus::assertDispatchedTimes(ProcessRepurposeItem::class, 1);
});

test('an api error is recorded without throwing', function () {
    Bus::fake();
    Http::fake([config('trypost.platforms.instagram.graph_api').'/*' => Http::response(['error' => ['message' => 'Invalid token']], 401)]);

    $repurpose = activeRepurpose();
    poll($repurpose);

    expect($repurpose->fresh()->last_error)->toContain('Invalid token')
        ->and($repurpose->items()->count())->toBe(0);
});

test('a rate limited source backs off instead of retrying next tick', function () {
    Bus::fake();
    config()->set('trypost.repurpose.backoff_minutes', 60);
    config()->set('trypost.repurpose.poll_interval_minutes', 15);
    Http::fake([config('trypost.platforms.instagram.graph_api').'/*' => Http::response(['error' => ['code' => 4, 'message' => 'Application request limit reached']], 400)]);

    $repurpose = activeRepurpose();
    poll($repurpose);

    $repurpose = $repurpose->fresh();

    expect($repurpose->status)->toBe(Status::Active)
        ->and(now()->diffInMinutes($repurpose->next_poll_at, absolute: true))->toBeGreaterThan(30);
});

test('a disconnected source is not polled', function () {
    Bus::fake();
    Http::fake();

    $repurpose = activeRepurpose();
    $repurpose->sourceAccount->update(['disconnected_at' => now()]);

    poll($repurpose);

    Http::assertNothingSent();
    expect($repurpose->items()->count())->toBe(0);
});

test('the command only dispatches for due active repurposes', function () {
    Bus::fake();

    activeRepurpose();
    Repurpose::factory()->create();
    Repurpose::factory()->paused()->create();
    Repurpose::factory()->disabled()->create();

    $this->artisan('repurpose:poll')->assertSuccessful();

    Bus::assertDispatchedTimes(PollRepurposeSource::class, 1);
});

test('a repurpose that is not due yet is not dispatched', function () {
    Bus::fake();

    activeRepurpose()->update(['next_poll_at' => now()->addMinutes(10)]);

    $this->artisan('repurpose:poll')->assertSuccessful();

    Bus::assertNotDispatched(PollRepurposeSource::class);
});
