<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Post\PostMetricsFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

test('web and REST post metrics read the same persisted observation without a provider call', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $access = createApiTestToken();
    $user = $access['user'];
    $workspace = $access['workspace'];
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $destination = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform_post_id' => '123',
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $destination->id,
        'platform' => Platform::Instagram,
        'content_type' => PublicationContentType::Reel,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'analytics_publication_id' => $publication->id,
        'snapshot_date' => '2026-09-20',
        'reactions_count' => 7,
        'watch_time_milliseconds' => 180000,
        'metrics' => [
            'reactions' => ['value' => 7, 'unit' => 'count', 'availability' => 'available'],
            'watch_time_milliseconds' => ['value' => 180000, 'unit' => 'milliseconds', 'availability' => 'available'],
        ],
    ]);
    Http::fake();

    $this->actingAs($user)
        ->getJson(route('app.posts.platforms.metrics', [$post, $destination]))
        ->assertOk()
        ->assertJsonPath('publication.content_type', 'reel')
        ->assertJsonPath('snapshot.reactions_count', 7)
        ->assertJsonPath('metrics.watch_time_milliseconds.value', 180000);

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.posts.metrics', $post))
        ->assertOk()
        ->assertJsonPath('platforms.0.metrics.metrics.reactions.value', 7);

    expect(app(PostMetricsFetcher::class)->forPlatform($destination)['snapshot']['reactions_count'])->toBe(7);
    Http::assertNothingSent();
});

test('excluded destinations expose no analytics and a foreign post cannot be read', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $access = createApiTestToken();
    $account = SocialAccount::factory()->create(['workspace_id' => $access['workspace']->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->published()->create(['workspace_id' => $access['workspace']->id, 'user_id' => $access['user']->id]);
    $destination = PostPlatform::factory()->linkedin()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $this->actingAs($access['user'])
        ->getJson(route('app.posts.platforms.metrics', [$post, $destination]))
        ->assertOk()
        ->assertJsonPath('unsupported', true)
        ->assertJsonPath('reason', 'platform_not_supported');

    $foreignPost = Post::factory()->published()->create();
    $this->actingAs($access['user'])
        ->getJson(route('app.posts.platforms.metrics', [$foreignPost, $destination]))
        ->assertNotFound();
});

test('post detail loads all destination observations in bounded queries', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id]);

    foreach (range(1, 3) as $number) {
        $destination = PostPlatform::factory()->instagram()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform_post_id' => "provider-{$number}",
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'post_platform_id' => $destination->id,
            'platform' => Platform::Instagram,
            'provider_post_id' => "provider-{$number}",
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'analytics_publication_id' => $publication->id,
            'snapshot_date' => '2026-09-23',
            'reactions_count' => $number,
        ]);

        if ($number === 1) {
            AnalyticsPublicationDailySnapshot::factory()->create([
                'analytics_publication_id' => $publication->id,
                'snapshot_date' => '2026-09-22',
                'reactions_count' => 100,
            ]);
        }
    }

    $post = $post->fresh();
    $reads = [];
    DB::listen(function ($query) use (&$reads): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $reads[] = $query->sql;
        }
    });

    $metrics = app(PostMetricsFetcher::class)->forPost($post);

    expect($metrics)->toHaveCount(3)
        ->and($metrics->pluck('metrics.snapshot.reactions_count')->sort()->values()->all())->toBe([1, 2, 3])
        ->and($reads)->toHaveCount(3);
});
