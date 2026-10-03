<?php

declare(strict_types=1);

use App\Actions\Analytics\ListChannelPublicationPerformance;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Dto\Analytics\DateRange;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Analytics\ChannelMetrics;
use App\Support\Analytics\SyncCadence;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo('2026-09-30 12:00 UTC');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->instagram = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);
});

/**
 * @param  array<string, mixed>  $metrics
 * @param  array<string, mixed>  $attributes
 */
function channelInsightsPublication(SocialAccount $account, string $publishedAt, array $metrics = [], array $attributes = []): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'provider_published_at' => CarbonImmutable::parse($publishedAt, 'UTC'),
        ...$attributes,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => CarbonImmutable::parse($publishedAt, 'UTC')->toDateString(),
        ...$metrics,
    ]);

    return $publication;
}

test('the insights page renders the channel report and its publications', function () {
    $publication = channelInsightsPublication($this->instagram, '2026-09-20 10:00:00', ['reactions_count' => 8, 'comments_count' => 2], [
        'preview_metadata' => ['thumbnail_url' => 'https://cdn.example.com/a.jpg'],
    ]);
    $post = Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);
    $destination = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->instagram->id,
        'platform' => Platform::Instagram,
    ]);
    $insecure = channelInsightsPublication($this->instagram, '2026-09-19 10:00:00', ['reactions_count' => 3], [
        'preview_metadata' => ['thumbnail_url' => 'http://cdn.example.com/b.jpg'],
        'post_platform_id' => $destination->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels/Insights')
            ->where('supported', true)
            ->where('channel.id', $this->instagram->id)
            ->has('channel.posting_goal')
            ->has('channel.sent_this_week')
            ->where('report.summary.posts.value', 2)
            ->where('report.summary.reactions.value', 11)
            ->missing('report.available_metrics')
            ->where('availableMetrics', fn ($metrics): bool => collect($metrics)->contains('reactions'))
            ->missing('report.filters')
            ->where('filters.range', '30d')
            ->where('filters.start', '2026-09-01')
            ->where('filters.end', '2026-09-30')
            ->where('filters.period', 'current')
            ->where('filters.sort', 'reactions')
            ->where('sortableMetrics', ChannelMetrics::sortable())
            ->has('publications.data', 2)
            ->where('publications.data.0.id', $publication->id)
            ->where('publications.data.0.rank', 1)
            ->where('publications.data.0.thumbnail_url', 'https://cdn.example.com/a.jpg')
            ->where('publications.data.0.metrics.reactions', 8)
            ->where('publications.data.0.url', null)
            ->where('publications.data.0.permalink', $publication->permalink)
            ->where('publications.data.1.id', $insecure->id)
            ->where('publications.data.1.url', route('app.posts.index', ['post' => $post->id]))
            ->where('sync', SyncCadence::toArray())
            ->where('publications.data.1.rank', 2)
            ->where('publications.data.1.thumbnail_url', null)
            ->etc());
});

test('publications sort by the chosen metric with nulls last and ties by published date', function () {
    $older = channelInsightsPublication($this->instagram, '2026-09-10 10:00:00', ['reactions_count' => 5, 'views_count' => 100]);
    $top = channelInsightsPublication($this->instagram, '2026-09-11 10:00:00', ['reactions_count' => 10, 'views_count' => null]);
    $middle = channelInsightsPublication($this->instagram, '2026-09-12 10:00:00', ['reactions_count' => null, 'views_count' => 50]);
    $newer = channelInsightsPublication($this->instagram, '2026-09-13 10:00:00', ['reactions_count' => 5, 'views_count' => 100]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('publications.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$top->id, $newer->id, $older->id, $middle->id])
            ->etc());

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'sort' => 'views']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'views')
            ->where('publications.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$newer->id, $older->id, $middle->id, $top->id])
            ->etc());
});

test('engagement rate sorts by engagement over exposure', function () {
    $low = channelInsightsPublication($this->instagram, '2026-09-10 10:00:00', ['engagement_count' => 1, 'exposure_count' => 3]);
    $high = channelInsightsPublication($this->instagram, '2026-09-11 10:00:00', ['engagement_count' => 2, 'exposure_count' => 3]);
    $unexposed = channelInsightsPublication($this->instagram, '2026-09-12 10:00:00', ['engagement_count' => 5, 'exposure_count' => 0]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'sort' => 'engagement_rate']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('publications.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$high->id, $low->id, $unexposed->id])
            ->where('publications.data.0.metrics.engagement_rate', 66.67)
            ->where('publications.data.2.metrics.engagement_rate', null)
            ->etc());
});

test('the previous period lists only publications in the previous range', function () {
    $previous = channelInsightsPublication($this->instagram, '2026-09-05 10:00:00', ['reactions_count' => 1]);
    $current = channelInsightsPublication($this->instagram, '2026-09-20 10:00:00', ['reactions_count' => 1]);
    $query = ['account' => $this->instagram, 'range' => 'custom', 'start' => '2026-09-11', 'end' => '2026-09-20'];

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', [...$query, 'period' => 'previous']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.previous_range.start', '2026-09-01')
            ->where('report.previous_range.end', '2026-09-10')
            ->where('filters.period', 'previous')
            ->has('publications.data', 1)
            ->where('publications.data.0.id', $previous->id)
            ->etc());

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $query))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('publications.data', 1)
            ->where('publications.data.0.id', $current->id)
            ->etc());
});

test('the publication table is paginated by the default page size', function () {
    $size = (int) config('app.pagination.default');

    foreach (range(1, $size + 1) as $day) {
        channelInsightsPublication($this->instagram, CarbonImmutable::parse('2026-08-01 10:00:00', 'UTC')->addDays($day % 28)->toDateTimeString(), ['reactions_count' => $day]);
    }

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'range' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-31']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('publications.data', $size)
            ->where('publications.data.0.metrics.reactions', $size + 1)
            ->etc());

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'range' => 'custom', 'start' => '2026-08-01', 'end' => '2026-08-31', 'page' => 2]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('publications.data', 1)
            ->where('publications.data.0.rank', $size + 1)
            ->where('publications.data.0.metrics.reactions', 1)
            ->etc());
});

test('a sort metric no publication reports falls back to published date order', function () {
    $older = channelInsightsPublication($this->instagram, '2026-09-10 10:00:00', ['comments_count' => 3]);
    $newer = channelInsightsPublication($this->instagram, '2026-09-12 10:00:00', ['comments_count' => 1]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'sort' => 'reactions']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'comments')
            ->where('publications.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$older->id, $newer->id])
            ->etc());

    expect(app(ListChannelPublicationPerformance::class)->handle($this->instagram, new DateRange(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30')), 'saves')->pluck('id')->all())
        ->toBe([$newer->id, $older->id]);
});

test('a valid sort the channel does not report falls back to the first available sortable metric', function () {
    $older = channelInsightsPublication($this->instagram, '2026-09-10 10:00:00', ['reactions_count' => 3]);
    $newer = channelInsightsPublication($this->instagram, '2026-09-12 10:00:00', ['reactions_count' => 1]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'sort' => 'saves']))
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.sort', 'reactions')
            ->where('publications.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$older->id, $newer->id])
            ->etc());
});

test('the default sort is the first sortable metric the channel reports', function () {
    $low = channelInsightsPublication($this->instagram, '2026-09-10 10:00:00', ['views_count' => 5]);
    $high = channelInsightsPublication($this->instagram, '2026-09-12 10:00:00', ['views_count' => 50]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('availableMetrics', ['followers', 'posts', 'views'])
            ->where('filters.sort', 'views')
            ->where('publications.data', fn ($rows) => collect($rows)->pluck('id')->all() === [$high->id, $low->id])
            ->etc());
});

test('an unknown or injected sort is a validation error', function (string $sort) {
    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'sort' => $sort]))
        ->assertRedirect()
        ->assertSessionHasErrors('sort');
})->with(['bogus', 'views;drop']);

test('an unsupported channel renders without a report or publications', function () {
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $linkedin))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels/Insights')
            ->where('supported', false)
            ->where('channel.id', $linkedin->id)
            ->missing('report')
            ->missing('publications'));
});

test('a channel of another workspace is not found, even with an invalid sort', function () {
    $foreign = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $foreign))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $foreign, 'sort' => 'bogus']))
        ->assertNotFound();
});

test('a member who needs approval can open the insights page', function () {
    $requester = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($requester->id, membershipPivot('approval'));
    $requester->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($requester)
        ->get(route('app.channels.insights', $this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('channels/Insights')
            ->where('supported', true)
            ->etc());
});

test('a range preset resolves through the preset action without clamping to the channel data', function () {
    channelInsightsPublication($this->instagram, '2026-09-20 10:00:00', ['reactions_count' => 1]);
    $recent = channelInsightsPublication($this->instagram, '2026-09-28 10:00:00', ['reactions_count' => 1]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'range' => '7d']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.range', '7d')
            ->where('filters.start', '2026-09-24')
            ->where('filters.end', '2026-09-30')
            ->where('report.range.start', '2026-09-24')
            ->where('report.range.end', '2026-09-30')
            ->has('publications.data', 1)
            ->where('publications.data.0.id', $recent->id)
            ->etc());
});

test('empty dates with a preset range are ignored instead of rejected', function () {
    channelInsightsPublication($this->instagram, '2026-09-28 10:00:00', ['reactions_count' => 1]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'range' => '7d', 'start' => '', 'end' => '']))
        ->assertOk()
        ->assertSessionHasNoErrors()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.range', '7d')
            ->where('filters.start', '2026-09-24')
            ->where('filters.end', '2026-09-30')
            ->etc());
});

test('a full load resolves the channel analytics key once', function () {
    channelInsightsPublication($this->instagram, '2026-09-20 10:00:00', ['reactions_count' => 1]);
    $this->partialMock(ResolveAnalyticsAccountKey::class, fn (MockInterface $mock) => $mock
        ->shouldReceive('for')
        ->once()
        ->passthru());

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('report.summary')
            ->has('availableMetrics')
            ->has('publications.data', 1)
            ->etc());
});

test('a sort or period change reloads the table and filters without the report', function () {
    channelInsightsPublication($this->instagram, '2026-09-20 10:00:00', ['reactions_count' => 8, 'views_count' => 3]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', ['account' => $this->instagram, 'sort' => 'views']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly(['publications', 'filters'], fn (Assert $reload) => $reload
                ->where('filters.sort', 'views')
                ->has('publications.data', 1)
                ->missing('report')
                ->missing('availableMetrics')));
});

test('a full load resolves the analytics bounds once', function () {
    channelInsightsPublication($this->instagram, '2026-09-20 10:00:00', ['reactions_count' => 1]);
    $boundsQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$boundsQueries): void {
        if (str_contains($query->sql, 'MIN(date) as earliest')) {
            $boundsQueries++;
        }
    });

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $this->instagram))
        ->assertOk();

    expect($boundsQueries)->toBe(1);
});

test('the coverage poll reloads the report without recomputing the available metrics', function () {
    channelInsightsPublication($this->instagram, '2026-09-20 10:00:00', ['reactions_count' => 8]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights', $this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->reloadOnly('report', fn (Assert $reload) => $reload
                ->has('report.summary')
                ->missing('availableMetrics')
                ->missing('publications')));
});
