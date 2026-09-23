<?php

declare(strict_types=1);

use App\Enums\Analytics\ExposureKind;
use App\Enums\Analytics\MetricAvailability;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricPrecision;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\MetricUnit;
use App\Enums\Analytics\ObservationProvenance;
use App\Enums\Analytics\PublicationAvailability;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

test('analytics tables expose the portable persistence contract', function () {
    expect(Schema::hasColumns('analytics_account_daily_snapshots', [
        'id', 'workspace_id', 'social_account_id', 'social_account_key', 'network',
        'platform_user_id', 'platform', 'account_display_name', 'account_username',
        'account_avatar_url', 'snapshot_date', 'followers_count', 'metrics',
        'provenance', 'precision', 'provider_observed_at', 'collected_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('analytics_publications', [
            'id', 'workspace_id', 'social_account_id', 'social_account_key',
            'post_platform_id', 'network', 'platform_user_id', 'platform',
            'provider_post_id', 'provider_published_at', 'origin', 'content_type',
            'availability', 'provider_content_type', 'permalink', 'excerpt',
            'preview_metadata', 'account_display_name', 'account_username',
            'account_avatar_url', 'first_seen_at', 'last_seen_at',
            'provider_synced_at', 'provider_metadata',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('analytics_publication_daily_snapshots', [
            'id', 'analytics_publication_id', 'snapshot_date', 'collected_at',
            'provider_observed_at', 'metrics', 'reactions_count', 'comments_count',
            'shares_count', 'saves_count', 'views_count', 'impressions_count',
            'reach_count', 'engagement_count', 'exposure_count', 'exposure_kind',
            'watch_time_milliseconds', 'average_watch_time_milliseconds',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('analytics_publication_daily_snapshots', 'workspace_id'))->toBeFalse()
        ->and(Schema::hasColumns('analytics_sync_states', [
            'id', 'social_account_id', 'collector', 'status', 'checkpoint',
            'target_since', 'oldest_reached_at', 'high_watermark_at',
            'last_success_at', 'last_error_category',
        ]))->toBeTrue();
});

test('same-network accounts remain distinct and historical facts survive account deletion', function () {
    $workspace = Workspace::factory()->create();
    $firstAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => 'instagram-1',
        'username' => 'first-account',
    ]);
    $secondAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => 'instagram-2',
        'username' => 'second-account',
    ]);

    foreach ([$firstAccount, $secondAccount] as $account) {
        DB::table('analytics_account_daily_snapshots')->insert([
            'id' => Str::uuid()->toString(),
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => $account->platform->value,
            'account_display_name' => $account->display_name,
            'account_username' => $account->username,
            'snapshot_date' => '2026-09-23',
            'followers_count' => 0,
            'provenance' => ObservationProvenance::Actual->value,
            'precision' => MetricPrecision::Exact->value,
            'collected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('analytics_publications')->insert([
            'id' => Str::uuid()->toString(),
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => $account->platform->value,
            'provider_post_id' => 'same-provider-post-id',
            'provider_published_at' => now(),
            'origin' => PublicationOrigin::External->value,
            'content_type' => PublicationContentType::Image->value,
            'availability' => PublicationAvailability::Available->value,
            'account_display_name' => $account->display_name,
            'account_username' => $account->username,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    DB::table('analytics_sync_states')->insert([
        'id' => Str::uuid()->toString(),
        'social_account_id' => $firstAccount->id,
        'collector' => SyncCollector::PublicationBackfill->value,
        'status' => SyncStatus::Pending->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('analytics_account_daily_snapshots')->count())->toBe(2)
        ->and(DB::table('analytics_publications')->count())->toBe(2);

    $firstAccount->deleteQuietly();

    $this->assertDatabaseHas('analytics_account_daily_snapshots', [
        'social_account_id' => null,
        'social_account_key' => $firstAccount->id,
        'platform' => Platform::Instagram->value,
        'account_username' => 'first-account',
        'followers_count' => 0,
    ]);
    $this->assertDatabaseHas('analytics_publications', [
        'social_account_id' => null,
        'social_account_key' => $firstAccount->id,
        'platform' => Platform::Instagram->value,
        'account_username' => 'first-account',
    ]);
    $this->assertDatabaseMissing('analytics_sync_states', [
        'social_account_id' => $firstAccount->id,
    ]);
});

test('analytics enums expose stable persisted values', function (string $enum, array $values) {
    expect(enum_exists($enum))->toBeTrue()
        ->and(array_column($enum::cases(), 'value'))->toEqual($values);
})->with([
    'observation provenance' => [ObservationProvenance::class, ['actual', 'carried_forward']],
    'metric precision' => [MetricPrecision::class, ['exact', 'approximate', 'estimated', 'experimental']],
    'publication origin' => [PublicationOrigin::class, ['trypost', 'external']],
    'publication availability' => [PublicationAvailability::class, ['available', 'deleted', 'unavailable']],
    'publication content type' => [PublicationContentType::class, ['text', 'image', 'carousel', 'video', 'reel', 'story', 'short', 'link', 'poll', 'unknown']],
    'exposure kind' => [ExposureKind::class, ['reach', 'impressions', 'views']],
    'metric unit' => [MetricUnit::class, ['count', 'milliseconds', 'percent']],
    'metric time basis' => [MetricTimeBasis::class, ['lifetime', 'range', 'rolling_90_days', 'snapshot']],
    'metric availability' => [MetricAvailability::class, ['available', 'unsupported', 'unavailable', 'delayed', 'privacy_limited']],
    'sync collector' => [SyncCollector::class, ['publication_backfill', 'publication_discovery']],
    'sync status' => [SyncStatus::class, ['pending', 'running', 'complete', 'partial', 'provider_limited', 'failed']],
    'metric key' => [MetricKey::class, [
        'reactions', 'comments', 'replies', 'shares', 'reposts', 'quotes', 'saves', 'bookmarks',
        'views', 'video_views', 'impressions', 'reach', 'engagements', 'total_interactions',
        'engagement_rate', 'clicks', 'link_clicks', 'pin_clicks', 'pin_click_rate',
        'outbound_clicks', 'outbound_click_rate', 'save_rate', 'follows', 'profile_visits',
        'profile_activity', 'watch_time_milliseconds', 'average_watch_time_milliseconds',
        'average_percentage_viewed', 'skip_rate', 'engaged_views', 'video_views_10_seconds',
        'video_views_95_percent', 'video_quartile_25', 'video_quartile_50',
        'video_quartile_75', 'video_quartile_100', 'total_play_time_milliseconds',
        'average_video_play_time_milliseconds', 'total_audience', 'engaged_audience',
        'subscribers_gained', 'subscribers_lost', 'story_navigation', 'story_taps_forward',
        'story_taps_back', 'story_exits', 'story_swipes_forward', 'unique_viewers',
    ]],
]);
