<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationOrigin;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Analytics\GetAnalyticsPublicationTool;
use App\Mcp\Tools\Analytics\GetAnalyticsReportTool;
use App\Mcp\Tools\Analytics\GetChannelInsightsTool;
use App\Mcp\Tools\Analytics\ListChannelPublicationsTool;
use App\Mcp\Tools\Post\GetPostMetricsTool;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    Http::fake();
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    $this->range = ['start' => '2026-09-01', 'end' => '2026-09-30'];

    $this->seedInsightsParityChannel = function (Platform $platform, int $followers, int $reactions, string $username): array {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform' => $platform,
            'username' => $username,
        ]);
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => $platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => $platform,
            'date' => '2026-09-20',
            'followers_count' => $followers,
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => $platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => $platform,
            'origin' => PublicationOrigin::External,
            'provider_published_at' => '2026-09-20 10:00:00',
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => '2026-09-20',
            'reactions_count' => $reactions,
            'comments_count' => 1,
        ]);

        return [$account, $publication];
    };
});

test('the web, api and mcp reports show the same summary totals for the same range', function () {
    ($this->seedInsightsParityChannel)(Platform::Instagram, 120, 7, 'alpha');
    ($this->seedInsightsParityChannel)(Platform::Facebook, 30, 5, 'bravo');

    $this->actingAs($this->user)
        ->get(route('app.insights', $this->range))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.followers.value', 150)
            ->where('report.summary.posts.value', 2)
            ->where('report.summary.reactions.value', 12)
            ->where('report.summary.comments.value', 2)
            ->etc());

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.index', $this->range))
        ->assertOk()
        ->assertJsonPath('summary.followers.value', 150)
        ->assertJsonPath('summary.posts.value', 2)
        ->assertJsonPath('summary.reactions.value', 12)
        ->assertJsonPath('summary.comments.value', 2);

    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsReportTool::class, $this->range)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('summary.followers.value', 150)
            ->where('summary.posts.value', 2)
            ->where('summary.reactions.value', 12)
            ->where('summary.comments.value', 2)
            ->etc());
});

test('imported external posts count in the web, api and mcp reports alike', function () {
    [, $publication] = ($this->seedInsightsParityChannel)(Platform::Instagram, 10, 9, 'alpha');

    $this->actingAs($this->user)
        ->get(route('app.insights', $this->range))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.top_posts.reactions.0.id', $publication->id)
            ->where('report.top_posts.reactions.0.origin', 'external')
            ->etc());

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.index', $this->range))
        ->assertJsonPath('top_posts.reactions.0.id', $publication->id)
        ->assertJsonPath('top_posts.reactions.0.origin', 'external');

    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsReportTool::class, $this->range)
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('top_posts.reactions.0.id', $publication->id)
            ->where('top_posts.reactions.0.origin', 'external')
            ->etc());
});

test('the web, api and mcp filter the workspace report by channel alike', function () {
    [$instagram] = ($this->seedInsightsParityChannel)(Platform::Instagram, 120, 7, 'alpha');
    ($this->seedInsightsParityChannel)(Platform::Facebook, 30, 5, 'bravo');
    $filtered = [...$this->range, 'channels' => [$instagram->id, (string) Str::uuid()]];

    $web = null;
    $this->actingAs($this->user)
        ->get(route('app.insights', $filtered))
        ->assertInertia(function (Assert $page) use (&$web) {
            $page->where('report.summary.posts.value', 1)
                ->where('report.summary.followers.value', 120)
                ->etc();
            $web = data_get($page->toArray(), 'props.report.summary');
        });

    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.index', $filtered))
        ->assertOk()
        ->assertJsonPath('summary.posts.value', 1)
        ->assertJsonPath('summary.followers.value', 120)
        ->json('summary');

    expect($api)->toEqual($web);

    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsReportTool::class, $filtered)
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('summary', $web)
            ->etc());
});

test('range presets are accepted and custom without dates is refused alike by web, api and mcp', function () {
    ($this->seedInsightsParityChannel)(Platform::Instagram, 120, 7, 'alpha');

    $this->actingAs($this->user)
        ->getJson(route('app.insights', ['range' => 'custom']))
        ->assertUnprocessable();

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.index', ['range' => 'custom']))
        ->assertUnprocessable();

    $message = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.index', ['range' => 'custom']))
        ->json('errors.start.0');

    $api = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.index', ['range' => '30d']))
        ->assertOk()
        ->json('summary');

    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsReportTool::class, ['range' => 'custom'])
        ->assertHasErrors([$message]);

    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsReportTool::class, ['range' => '30d'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('summary', $api)
            ->etc());
});

test('last_month is refused alike by web, api and mcp', function () {
    ($this->seedInsightsParityChannel)(Platform::Instagram, 120, 7, 'alpha');

    $this->actingAs($this->user)
        ->getJson(route('app.insights', ['range' => 'last_month']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('range');

    auth()->forgetGuards();
    $message = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.index', ['range' => 'last_month']))
        ->assertUnprocessable()
        ->json('errors.range.0');

    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsReportTool::class, ['range' => 'last_month'])
        ->assertHasErrors([$message]);
});

test('no arguments and start and end alone give the same report on api and mcp', function () {
    ($this->seedInsightsParityChannel)(Platform::Instagram, 120, 7, 'alpha');

    foreach ([[], $this->range] as $input) {
        auth()->forgetGuards();
        $api = $this->withHeaders(parityApi($this->token))
            ->getJson(route('api.analytics.index', $input))
            ->assertOk()
            ->json();

        TryPostServer::actingAs($this->user)
            ->tool(GetAnalyticsReportTool::class, $input)
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('summary', $api['summary'])
                ->where('range', $api['range'])
                ->etc());
    }
});

test('the publication detail is the same through the api and mcp and is isolated per workspace', function () {
    [, $publication] = ($this->seedInsightsParityChannel)(Platform::Instagram, 10, 6, 'alpha');
    $foreign = AnalyticsPublication::factory()->create(['platform' => Platform::Instagram]);

    $this->actingAs($this->user)
        ->getJson(route('app.insights.publications.details', $publication->id))
        ->assertOk()
        ->assertJsonPath('publication.id', $publication->id)
        ->assertJsonPath('snapshot.reactions_count', 6);

    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.publications.show', $publication->id))
        ->assertOk()
        ->assertJsonPath('publication.id', $publication->id)
        ->assertJsonPath('snapshot.reactions_count', 6);

    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => $publication->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('publication.id', $publication->id)
            ->where('snapshot.reactions_count', 6)
            ->etc());

    $this->actingAs($this->user)->getJson(route('app.insights.publications.details', $foreign->id))->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.analytics.publications.show', $foreign->id))->assertNotFound();
    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => $foreign->id])
        ->assertHasErrors(['Publication not found.']);
});

test('the post metrics are the same on the web, the api and mcp', function () {
    $account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    $post = Post::factory()->forAccount($account)->published()->create([
        'user_id' => $this->user->id,
        'platform_post_id' => '123',
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'platform' => Platform::Instagram,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-20',
        'reactions_count' => 7,
        'metrics' => ['reactions' => ['value' => 7, 'unit' => 'count', 'availability' => 'available']],
    ]);

    $web = $this->actingAs($this->user)
        ->getJson(route('app.posts.metrics', $post))
        ->assertOk()
        ->assertJsonPath('metrics.reactions.value', 7)
        ->json();

    auth()->forgetGuards();
    $api = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.posts.metrics', $post))
        ->assertOk()
        ->assertJsonPath('post_id', $post->id)
        ->assertJsonPath('metrics.metrics.reactions.value', 7)
        ->json('metrics');

    expect($api)->toEqual($web);

    TryPostServer::actingAs($this->user)
        ->tool(GetPostMetricsTool::class, ['post_id' => $post->id])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('post_id', $post->id)
            ->where('metrics', $api)
            ->etc());

    $foreign = Post::factory()->published()->create();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.metrics', $foreign))->assertNotFound();
    TryPostServer::actingAs($this->user)
        ->tool(GetPostMetricsTool::class, ['post_id' => $foreign->id])
        ->assertHasErrors(['Post not found.']);
});

test('there is no api route or mcp tool to download the insights export', function () {
    $this->actingAs($this->user)
        ->get(route('app.insights.download', ['format' => 'csv', ...$this->range]))
        ->assertOk();

    expect(collect(Route::getRoutes()->getRoutesByName())->keys()->filter(fn (string $name) => str_starts_with($name, 'api.') && (str_contains($name, 'download') || str_contains($name, 'export'))))->toBeEmpty()
        ->and(collect((new ReflectionProperty(TryPostServer::class, 'tools'))->getDefaultValue())->filter(fn (string $tool) => str_contains($tool, 'Export') || str_contains($tool, 'Download')))->toBeEmpty();
});

/**
 * @return array{account: SocialAccount, label: WorkspaceLabel}
 */
function seedChannelInsightsParity(Workspace $workspace, User $user): array
{
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);

    foreach (['2026-09-01' => 100, '2026-09-10' => 112, '2026-09-25' => 130] as $date => $followers) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => Platform::Instagram->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => Platform::Instagram,
            'date' => $date,
            'followers_count' => $followers,
        ]);
    }

    foreach ([['2026-09-05', 40, 3, ContentType::InstagramFeed, true], ['2026-09-12', 90, 8, ContentType::InstagramReel, true], ['2026-09-18', 60, 11, null, false]] as [$day, $reach, $reactions, $type, $labelled]) {
        $post = null;

        if ($type !== null) {
            $post = Post::factory()->forAccount($account, $type)->published()->create(['user_id' => $user->id]);

            if ($labelled) {
                $post->labels()->attach($label->id);
            }
        }

        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => Platform::Instagram->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => Platform::Instagram,
            'post_id' => $post?->id,
            'origin' => $post === null ? PublicationOrigin::External : PublicationOrigin::TryPost,
            'provider_published_at' => "{$day} 10:00:00",
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => $day,
            'reactions_count' => $reactions,
            'comments_count' => 1,
            'reach_count' => $reach,
        ]);
    }

    return ['account' => $account, 'label' => $label];
}

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function webChannelInsightsProps(TestCase $test, User $user, SocialAccount $account, array $query): array
{
    $props = [];
    $test->actingAs($user)
        ->get(route('app.channels.insights', [$account, ...$query]))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $props = data_get($page->toArray(), 'props');
            $page->loadDeferredProps(function (Assert $reload) use (&$props) {
                $props = [...$props, ...data_get($reload->toArray(), 'props')];
            });
        });
    auth()->forgetGuards();

    return $props;
}

test('channel insights show the same summary, metrics, series and ranking on web, api and mcp for the same filters', function (Closure $filters) {
    ['account' => $account, 'label' => $label] = seedChannelInsightsParity($this->workspace, $this->user);
    $query = [...$this->range, 'sort' => 'reach', ...$filters($label)];
    $web = webChannelInsightsProps($this, $this->user, $account, $query);

    $api = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.channels.insights.show', [$account, ...$query]))
        ->assertOk()
        ->json();

    expect($api['summary'])->toEqual($web['report'])
        ->and($api['available_metrics'])->toEqual($web['availableMetrics'])
        ->and($api['metric_series'])->toEqual($web['metricSeries'])
        ->and($api['filters'])->toEqual($web['filters']);

    $list = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.channels.insights.publications', [$account, ...$query]))
        ->assertOk()
        ->json();

    expect($list['data'])->toEqual(data_get($web, 'publications.data'))
        ->and($list['filters'])->toEqual($web['filters']);

    $arguments = ['account_id' => $account->id, ...$query];
    TryPostServer::actingAs($this->user)
        ->tool(GetChannelInsightsTool::class, $arguments)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('summary', $api['summary'])
            ->where('available_metrics', $api['available_metrics'])
            ->where('metric_series', $api['metric_series'])
            ->where('filters', $api['filters']));

    TryPostServer::actingAs($this->user)
        ->tool(ListChannelPublicationsTool::class, $arguments)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('publications', $list['data'])
            ->where('filters', $list['filters'])
            ->etc());
})->with([
    'no filter' => [fn (WorkspaceLabel $label): array => []],
    'labels' => [fn (WorkspaceLabel $label): array => ['labels' => [$label->id]]],
    'untagged' => [fn (WorkspaceLabel $label): array => ['untagged' => '1']],
    'types' => [fn (WorkspaceLabel $label): array => ['types' => [ContentType::InstagramReel->value]]],
    'previous period' => [fn (WorkspaceLabel $label): array => ['period' => 'previous', 'range' => '30d']],
]);

test('channel insights series: reach is the total of the posts published each day and net followers is cumulative', function () {
    ['account' => $account, 'label' => $label] = seedChannelInsightsParity($this->workspace, $this->user);

    $series = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.channels.insights.show', [$account, ...$this->range, 'labels' => [$label->id]]))
        ->assertOk()
        ->assertJsonPath('summary.summary.posts.value', 2)
        ->json('metric_series');
    $byDay = collect($series['current'])->keyBy('start');

    expect(data_get($byDay, '2026-09-05.values.reach'))->toBe(40)
        ->and(data_get($byDay, '2026-09-12.values.reach'))->toBe(90)
        ->and(data_get($byDay, '2026-09-18.values.reach'))->toBe(0)
        ->and(data_get($series, 'totals.current.reach'))->toBe(130)
        ->and(data_get($byDay, '2026-09-25.values.net_followers'))->toBe(30);
});

test('channel insights refuse a foreign label and a post type of another platform with the web message', function (Closure $input, string $key) {
    ['account' => $account] = seedChannelInsightsParity($this->workspace, $this->user);
    $query = $input();

    $web = $this->actingAs($this->user)
        ->getJson(route('app.channels.insights', [$account, ...$query]))
        ->assertUnprocessable()
        ->json('errors')[$key][0];
    auth()->forgetGuards();

    foreach (['api.channels.insights.show', 'api.channels.insights.publications'] as $route) {
        $this->withHeaders(parityApi($this->token))
            ->getJson(route($route, [$account, ...$query]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$key => $web]);
    }

    foreach ([GetChannelInsightsTool::class, ListChannelPublicationsTool::class] as $tool) {
        TryPostServer::actingAs($this->user)
            ->tool($tool, ['account_id' => $account->id, ...$query])
            ->assertHasErrors([$web]);
    }
})->with([
    'foreign label' => [fn (): array => ['labels' => [WorkspaceLabel::factory()->create()->id]], 'labels.0'],
    'type of another platform' => [fn (): array => ['types' => [ContentType::LinkedInPost->value]], 'types.0'],
    'custom range without dates' => [fn (): array => ['range' => 'custom'], 'start'],
]);

test('channel insights refuse a channel of another workspace on web, api and mcp', function () {
    $foreign = SocialAccount::factory()->instagram()->create();

    $this->actingAs($this->user)->get(route('app.channels.insights', $foreign))->assertNotFound();
    auth()->forgetGuards();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.insights.show', $foreign))->assertNotFound();
    $this->withHeaders(parityApi($this->token))->getJson(route('api.channels.insights.publications', $foreign))->assertNotFound();

    foreach ([GetChannelInsightsTool::class, ListChannelPublicationsTool::class] as $tool) {
        TryPostServer::actingAs($this->user)
            ->tool($tool, ['account_id' => $foreign->id])
            ->assertHasErrors(['Social account not found.']);
    }
});

test('channel publications paginate with the app default on the api and mcp in the web order', function () {
    config()->set('app.pagination.default', 2);
    ['account' => $account] = seedChannelInsightsParity($this->workspace, $this->user);
    $query = [...$this->range, 'sort' => 'reach'];
    $web = webChannelInsightsProps($this, $this->user, $account, $query);

    $first = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.channels.insights.publications', [$account, ...$query]))
        ->assertOk()
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2)
        ->json('data');
    $second = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.channels.insights.publications', [$account, ...$query, 'page' => 2]))
        ->assertOk()
        ->assertJsonPath('meta.current_page', 2)
        ->json('data');

    expect(collect([...$first, ...$second])->pluck('id')->all())->toEqual(collect(data_get($web, 'publications.data'))->pluck('id')->all())
        ->and(collect([...$first, ...$second])->pluck('rank')->all())->toBe([1, 2, 3])
        ->and(collect([...$first, ...$second])->pluck('metrics.reach')->all())->toBe([90, 60, 40]);

    TryPostServer::actingAs($this->user)
        ->tool(ListChannelPublicationsTool::class, ['account_id' => $account->id, ...$query, 'page' => 2])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('publications', $second)
            ->where('total', 3)
            ->where('per_page', 2)
            ->where('current_page', 2)
            ->where('last_page', 2)
            ->etc());
});

test('the web, api and mcp count a sao paulo evening post on the viewer local day alike', function () {
    $this->user->update(['timezone' => 'America/Sao_Paulo']);
    [$account, $publication] = ($this->seedInsightsParityChannel)(Platform::Instagram, 50, 4, 'alpha');
    $publication->update(['provider_published_at' => '2026-09-11 01:00:00']);
    $range = ['start' => '2026-09-10', 'end' => '2026-09-10'];

    $this->actingAs($this->user)
        ->get(route('app.insights', $range))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 1)
            ->where('report.posts.buckets.0.start', '2026-09-10')
            ->where('report.posts.buckets.0.total', 1)
            ->etc());
    $web = webChannelInsightsProps($this, $this->user, $account, $range);

    $api = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.analytics.index', $range))
        ->assertOk()
        ->assertJsonPath('summary.posts.value', 1)
        ->assertJsonPath('posts.buckets.0.total', 1)
        ->json();
    $channel = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.channels.insights.show', [$account, ...$range]))
        ->assertOk()
        ->assertJsonPath('summary.summary.posts.value', 1)
        ->assertJsonPath('metric_series.current.0.values.posts', 1)
        ->json();
    $list = $this->withHeaders(parityApi($this->token))
        ->getJson(route('api.channels.insights.publications', [$account, ...$range]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $publication->id)
        ->json('data');

    expect($channel['summary'])->toEqual($web['report'])
        ->and($channel['metric_series'])->toEqual($web['metricSeries'])
        ->and($list)->toEqual(data_get($web, 'publications.data'));

    TryPostServer::actingAs($this->user)
        ->tool(GetAnalyticsReportTool::class, $range)
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('summary', $api['summary'])
            ->where('posts', $api['posts'])
            ->etc());
    TryPostServer::actingAs($this->user)
        ->tool(GetChannelInsightsTool::class, ['account_id' => $account->id, ...$range])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('summary', $channel['summary'])
            ->where('metric_series', $channel['metric_series'])
            ->etc());
    TryPostServer::actingAs($this->user)
        ->tool(ListChannelPublicationsTool::class, ['account_id' => $account->id, ...$range])
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('publications', $list)
            ->etc());
});
