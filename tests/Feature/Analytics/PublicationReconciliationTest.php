<?php

declare(strict_types=1);

use App\Actions\Analytics\SyncTryPostPublication;
use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Dto\Analytics\DiscoveredPublication;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\SyncTryPostPublication as SyncTryPostPublicationJob;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('external discovery followed by TryPost sync converges on one TryPost publication', function () {
    [$account, $postPlatform] = publicationFixture('remote-1');
    $upsert = app(UpsertAnalyticsPublication::class);

    $upsert->external($account, discoveredPublication('remote-1'));
    app(SyncTryPostPublication::class)->handle($postPlatform);

    $publication = AnalyticsPublication::sole();
    expect($publication->origin)->toBe(PublicationOrigin::TryPost)
        ->and($publication->post_platform_id)->toBe($postPlatform->id)
        ->and($publication->provider_published_at?->toISOString())->toBe('2026-09-20T12:00:00.000000Z');
});

test('TryPost sync followed by external discovery preserves TryPost ownership', function () {
    [$account, $postPlatform] = publicationFixture('remote-1');

    app(SyncTryPostPublication::class)->handle($postPlatform);
    app(UpsertAnalyticsPublication::class)->external($account, discoveredPublication(
        providerPostId: 'remote-1',
        excerpt: 'Provider copy',
    ));

    $publication = AnalyticsPublication::sole();
    expect($publication->origin)->toBe(PublicationOrigin::TryPost)
        ->and($publication->post_platform_id)->toBe($postPlatform->id)
        ->and($publication->excerpt)->toBe('Provider copy');
});

test('duplicate provider pages are idempotent', function () {
    [$account] = publicationFixture();
    $upsert = app(UpsertAnalyticsPublication::class);

    $upsert->external($account, discoveredPublication('remote-1'));
    $upsert->external($account, discoveredPublication('remote-1'));

    expect(AnalyticsPublication::count())->toBe(1);
});

test('TikTok public video id merges a provisional TryPost post with earlier discovery and snapshots', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    $postPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'content_type' => ContentType::TikTokVideo,
        'platform_post_id' => 'v_pub_provisional',
    ]);
    $provisional = app(SyncTryPostPublication::class)->handle($postPlatform);
    $discovered = app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: '123456789',
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Video,
        permalink: 'https://www.tiktok.com/@example/video/123456789',
    ));
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $provisional->id,
        'date' => '2026-09-21',
        'collected_at' => '2026-09-21 10:00:00',
        'views_count' => 5,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $discovered->id,
        'date' => '2026-09-21',
        'collected_at' => '2026-09-21 12:00:00',
        'views_count' => 12,
    ]);

    app(UpsertAnalyticsPublication::class)->reconcileTikTokPublicId($provisional, '123456789');

    expect(AnalyticsPublication::query()->count())->toBe(1)
        ->and($provisional->fresh()->remote_id)->toBe('123456789')
        ->and($provisional->fresh()->origin)->toBe(PublicationOrigin::TryPost)
        ->and(AnalyticsPublicationDailySnapshot::query()->count())->toBe(1)
        ->and($provisional->dailySnapshots()->sole()->views_count)->toBe(12)
        ->and($postPlatform->fresh()->platform_post_id)->toBe('123456789');

    app(UpsertAnalyticsPublication::class)->external($account, new DiscoveredPublication(
        providerPostId: '123456789',
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Video,
    ));
    expect(AnalyticsPublication::query()->count())->toBe(1);
});

test('database failures other than identity collisions are not swallowed', function () {
    [$account] = publicationFixture();

    expect(fn () => app(UpsertAnalyticsPublication::class)->external(
        $account,
        discoveredPublication(str_repeat('x', 192)),
    ))->toThrow(QueryException::class);
});

test('the same provider post id remains separate by account and workspace', function () {
    $workspace = Workspace::factory()->create();
    $firstAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $secondAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $otherAccount = SocialAccount::factory()->x()->create();
    $upsert = app(UpsertAnalyticsPublication::class);

    foreach ([$firstAccount, $secondAccount, $otherAccount] as $account) {
        $upsert->external($account, discoveredPublication('shared-provider-id'));
    }

    expect(AnalyticsPublication::count())->toBe(3)
        ->and(AnalyticsPublication::query()->where('workspace_id', $workspace->id)->count())->toBe(2);
});

test('queued TryPost sync survives social account deletion after dispatch', function () {
    [$account, $postPlatform] = publicationFixture();
    $postPlatform->update(['status' => Status::Pending]);
    $queuedJob = null;
    Queue::fake();

    $postPlatform->markAsPublished('remote-1', 'https://x.com/example/status/remote-1');

    Queue::assertPushed(SyncTryPostPublicationJob::class, function (SyncTryPostPublicationJob $job) use (&$queuedJob, $account, $postPlatform): bool {
        $queuedJob = $job;

        return $job->postPlatformId === $postPlatform->id
            && $job->identity->socialAccountId === $account->id
            && $job->queue === 'analytics'
            && $job->afterCommit === true;
    });

    $account->delete();
    app()->call([$queuedJob, 'handle']);

    $publication = AnalyticsPublication::sole();
    expect($publication->social_account_id)->toBeNull()
        ->and($publication->social_account_key)->toBe($account->id)
        ->and($publication->origin)->toBe(PublicationOrigin::TryPost)
        ->and($publication->post_platform_id)->toBe($postPlatform->id);
});

/** @return array{SocialAccount, PostPlatform} */
function publicationFixture(?string $providerPostId = null): array
{
    $account = SocialAccount::factory()->x()->create();
    $post = Post::factory()->create([
        'workspace_id' => $account->workspace_id,
        'content' => '<p>TryPost copy</p>',
    ]);
    $postPlatform = PostPlatform::factory()->x()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform_post_id' => $providerPostId ?? 'remote-1',
        'platform_url' => 'https://x.com/example/status/remote-1',
        'published_at' => CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
    ]);

    return [$account, $postPlatform];
}

function discoveredPublication(
    string $providerPostId = 'remote-1',
    string $excerpt = 'External copy',
): DiscoveredPublication {
    return new DiscoveredPublication(
        providerPostId: $providerPostId,
        publishedAt: CarbonImmutable::parse('2026-09-20 12:00:00', 'UTC'),
        contentType: PublicationContentType::Text,
        providerContentType: 'tweet',
        permalink: "https://x.com/example/status/{$providerPostId}",
        excerpt: $excerpt,
        previewMetadata: ['thumbnail_url' => 'https://example.com/thumb.jpg'],
        providerMetadata: ['source' => 'provider'],
    );
}
