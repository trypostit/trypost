<?php

declare(strict_types=1);

use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\GetPostMetricsTool;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;

test('REST and MCP post metrics read the same persisted observation without a provider call', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $access = createApiTestToken();
    $user = $access['user'];
    $workspace = $access['workspace'];
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $post = Post::factory()->forAccount($account)->published()->create(['user_id' => $user->id, 'platform_post_id' => '123']);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'platform' => Platform::Instagram,
        'content_type' => PublicationContentType::Reel,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-20',
        'reactions_count' => 7,
        'watch_time_milliseconds' => 180000,
        'metrics' => [
            'reactions' => ['value' => 7, 'unit' => 'count', 'availability' => 'available'],
            'watch_time_milliseconds' => ['value' => 180000, 'unit' => 'milliseconds', 'availability' => 'available'],
        ],
    ]);
    Http::fake();

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.posts.metrics', $post))
        ->assertOk()
        ->assertJsonPath('post_id', $post->id)
        ->assertJsonPath('platform', Platform::Instagram->value)
        ->assertJsonPath('metrics.available', true)
        ->assertJsonPath('metrics.metrics.reactions.value', 7)
        ->assertJsonPath('metrics.metrics.watch_time_milliseconds.value', 180000)
        ->assertJsonMissingPath('platforms')
        ->assertJsonMissingPath('analytics');

    TryPostServer::actingAs($user)
        ->tool(GetPostMetricsTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('post_id', $post->id)
            ->where('platform_post_id', '123')
            ->where('metrics.available', true)
            ->where('metrics.metrics.reactions.value', 7)
            ->where('metrics.metrics.watch_time_milliseconds.value', 180000)
            ->missing('platforms')
            ->missing('analytics')
            ->etc());

    expect(app(ReadPublicationAnalytics::class)->latestFor($post)['snapshot']['reactions_count'])->toBe(7);
    Http::assertNothingSent();
});

test('excluded destinations expose no analytics and a foreign post cannot be read', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $access = createApiTestToken();
    $account = SocialAccount::factory()->create(['workspace_id' => $access['workspace']->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->forAccount($account)->published()->create(['user_id' => $access['user']->id]);

    TryPostServer::actingAs($access['user'])
        ->tool(GetPostMetricsTool::class, ['post_id' => $post->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('metrics.unsupported', true)
            ->where('metrics.reason', 'platform_not_supported')
            ->etc());

    $foreignPost = Post::factory()->linkedin()->published()->create();
    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.posts.metrics', $foreignPost))
        ->assertNotFound();
});

test('post metrics preserve metric keys and availability from persisted observations', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->threads()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->published()->create();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'post_id' => $post->id,
        'platform' => Platform::Threads,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'metrics' => [
            'reactions' => ['value' => 9, 'unit' => 'count', 'availability' => 'available'],
        ],
    ]);

    expect(app(ReadPublicationAnalytics::class)->latestFor($post)['metrics']['reactions'])->toEqual([
        'value' => 9,
        'unit' => 'count',
        'availability' => 'available',
    ]);
});

test('post cards load the observations of every post in bounded queries', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $posts = collect();

    foreach (range(1, 3) as $number) {
        $post = Post::factory()->forAccount($account)->published()->create([
            'platform_post_id' => "provider-{$number}",
        ]);
        $posts->push($post);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'post_id' => $post->id,
            'platform' => Platform::Instagram,
            'remote_id' => "provider-{$number}",
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => '2026-09-23',
            'reactions_count' => $number,
        ]);

        if ($number === 1) {
            AnalyticsPublicationDailySnapshot::factory()->create([
                'publication_id' => $publication->id,
                'date' => '2026-09-22',
                'reactions_count' => 100,
            ]);
        }
    }

    $posts = $posts->map->fresh();
    $reads = [];
    DB::listen(function ($query) use (&$reads): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $reads[] = $query->sql;
        }
    });

    $metrics = collect(app(ReadPublicationAnalytics::class)->latestForPosts($workspace->id, $posts));

    expect($metrics)->toHaveCount(3)
        ->and($metrics->keys()->all())->toBe($posts->pluck('id')->all())
        ->and($metrics->pluck('snapshot.reactions_count')->sort()->values()->all())->toBe([1, 2, 3])
        ->and($reads)->toHaveCount(2);
});

test('post metrics derive the engagement rate from the snapshot exposure like the channel reports', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->x()->createQuietly(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->published()->create(['platform_post_id' => '1840000000000000000']);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'platform' => Platform::X,
        'content_type' => PublicationContentType::Text,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-20',
        'impressions_count' => 200,
        'engagement_count' => 7,
        'exposure_count' => 200,
        'exposure_kind' => 'impressions',
        'metrics' => [
            'impressions' => ['value' => 200, 'unit' => 'count', 'availability' => 'available'],
            'engagements' => ['value' => 7, 'unit' => 'count', 'availability' => 'available'],
        ],
    ]);

    $metrics = app(ReadPublicationAnalytics::class)->latestFor($post)['metrics'];

    expect(data_get($metrics, 'engagement_rate.value'))->toBe(3.5)
        ->and(data_get($metrics, 'engagement_rate.unit'))->toBe('percent')
        ->and(data_get($metrics, 'engagement_rate.availability'))->toBe('available');
});

test('post metrics leave the engagement rate out when the network reports no exposure', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->mastodon()->createQuietly(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account)->published()->create(['platform_post_id' => '117304063461175259']);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'platform' => Platform::Mastodon,
        'content_type' => PublicationContentType::Text,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-20',
        'engagement_count' => 6,
        'exposure_count' => null,
        'exposure_kind' => null,
        'metrics' => ['engagements' => ['value' => 6, 'unit' => 'count', 'availability' => 'available']],
    ]);

    expect(app(ReadPublicationAnalytics::class)->latestFor($post)['metrics'])->not->toHaveKey('engagement_rate');
});

test('tiktok post metrics return the saved video url and observation without a provider call', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $videoId = '7685359243088103444';
    $account = SocialAccount::factory()->tiktok()->create(['username' => 'tiktoker']);
    $post = Post::factory()->forAccount($account)->published()->create([
        'platform_post_id' => $videoId,
        'platform_url' => "https://www.tiktok.com/@tiktoker/video/{$videoId}",
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'network' => Platform::TikTok->network(),
        'platform' => Platform::TikTok,
        'remote_id' => $videoId,
        'content_type' => PublicationContentType::Video,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'views_count' => 5,
        'metrics' => ['views' => ['value' => 5, 'unit' => 'count', 'availability' => 'available']],
    ]);
    Http::fake();

    $analytics = app(ReadPublicationAnalytics::class)->forPost($post->fresh());

    expect($analytics)->toMatchArray([
        'platform_post_id' => $videoId,
        'platform_url' => "https://www.tiktok.com/@tiktoker/video/{$videoId}",
    ])->and($analytics['metrics']['metrics']['views']['value'])->toBe(5);

    Http::assertNothingSent();
});
