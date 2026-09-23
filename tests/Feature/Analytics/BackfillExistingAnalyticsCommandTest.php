<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\BackfillTryPostPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Models\AnalyticsPublication;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

test('local publication backfill persists only successful included destinations with live identities', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $published = PostPlatform::factory()->instagram()->published()->create([
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);
    $published->post->update(['workspace_id' => $workspace->id]);
    $failed = PostPlatform::factory()->instagram()->failed()->create([
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    app()->call([new BackfillTryPostPublications([$published->id, $failed->id]), 'handle']);

    expect(AnalyticsPublication::query()->where('post_platform_id', $published->id)->exists())->toBeTrue()
        ->and(AnalyticsPublication::query()->where('post_platform_id', $failed->id)->exists())->toBeFalse();
});

test('rollout command dispatches bounded jobs only for eligible scoped accounts and destinations', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $eligible = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'is_active' => true,
        'status' => Status::Connected,
    ]);
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'is_active' => true,
        'status' => Status::Connected,
    ]);
    SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'is_active' => false,
        'status' => Status::Connected,
    ]);
    $published = PostPlatform::factory()->instagram()->published()->create([
        'social_account_id' => $eligible->id,
        'platform' => $eligible->platform,
    ]);
    $published->post->update(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->instagram()->create([
        'social_account_id' => $eligible->id,
        'platform' => $eligible->platform,
        'status' => PostPlatformStatus::Failed,
    ]);
    Bus::fake();

    expect(SocialAccount::query()->connected()->active()->includedInAnalytics()
        ->where('workspace_id', $workspace->id)->pluck('id')->all())->toBe([$eligible->id]);

    $this->artisan('analytics:backfill-existing', ['--workspace' => $workspace->id])
        ->assertSuccessful();

    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $eligible->id);
    Bus::assertDispatched(BackfillTryPostPublications::class, fn ($job): bool => $job->postPlatformIds === [$published->id]);
    Bus::assertDispatchedTimes(BootstrapAccountAnalytics::class, 1);
    Bus::assertDispatchedTimes(BackfillTryPostPublications::class, 1);
});

test('rollout reports orphaned historical destinations without inventing an identity', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $destination = PostPlatform::factory()->instagram()->published()->create();
    $destination->post->update(['workspace_id' => $workspace->id]);
    $destination->updateQuietly(['social_account_id' => null]);
    Bus::fake();

    $this->artisan('analytics:backfill-existing', ['--workspace' => $workspace->id])
        ->expectsOutputToContain('historical_identity_unrecoverable=1')
        ->assertSuccessful();

    Bus::assertNotDispatched(BackfillTryPostPublications::class);
});
