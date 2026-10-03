<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\Analytics\SyncCadence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo('2026-09-30 12:00 UTC');

    $this->user = User::factory()->create(['timezone' => 'UTC']);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->publish = function (SocialAccount $account, int $reactions, string $excerpt, ?WorkspaceLabel $label = null, string $publishedAt = '2026-09-15 12:00:00', array $metrics = []): AnalyticsPublication {
        $post = Post::factory()->published()->create(['workspace_id' => $account->workspace_id, 'user_id' => $this->user->id]);

        if ($label !== null) {
            $post->labels()->attach($label);
        }

        $destination = PostPlatform::factory()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'post_platform_id' => $destination->id,
            'platform' => $account->platform,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'account_username' => $account->username,
            'excerpt' => $excerpt,
            'permalink' => "https://example.com/{$excerpt}",
            'provider_published_at' => CarbonImmutable::parse($publishedAt, 'UTC'),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => substr($publishedAt, 0, 10),
            'reactions_count' => $reactions,
            'comments_count' => 1,
            'impressions_count' => $reactions * 10,
            'metrics' => $metrics,
        ]);

        return $publication;
    };

    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram, 'username' => 'alpha']);
    $this->facebook = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Facebook, 'username' => 'bravo']);
    $this->label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    foreach ([[$this->instagram, 100], [$this->facebook, 200]] as [$account, $followers]) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => $account->platform,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'date' => '2026-09-20',
            'followers_count' => $followers,
            'account_username' => $account->username,
        ]);
    }

    ($this->publish)($this->instagram, 10, 'alpha-reel', $this->label, '2026-09-15 12:00:00', [
        'reposts' => ['value' => 4, 'unit' => 'count', 'availability' => 'available'],
    ]);
    ($this->publish)($this->facebook, 20, 'bravo-post', null, '2026-09-16 09:30:00', [
        'clicks' => ['value' => 7, 'unit' => 'count', 'availability' => 'available'],
    ]);
});

function insightsExportRoute(string $format, array $query = []): string
{
    return route('app.insights.download', ['format' => $format, 'range' => '30d', ...$query]);
}

/**
 * @return list<list<string>>
 */
function insightsCsvRows(string $content): array
{
    $lines = preg_split('/\r?\n/', trim(ltrim($content, "\u{FEFF}")));

    return array_map(fn (string $line): array => str_getcsv($line, escape: ''), $lines);
}

test('the CSV export streams the summary, performance, followers and posts the page shows', function () {
    $response = $this->actingAs($this->user)->get(insightsExportRoute('csv'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('trypost-insights-2026-09-30.csv');

    $content = $response->streamedContent();
    $rows = insightsCsvRows($content);

    expect($content)->toStartWith("\u{FEFF}")
        ->and($rows)->toContain(['Insights'])
        ->and($rows)->toContain(['Range', '2026-09-01 – 2026-09-30'])
        ->and($rows)->toContain(['Metric', 'Value', 'Previous period', 'Change'])
        ->and($rows)->toContain(['Posts', '2', '0', ''])
        ->and($rows)->toContain(['Total followers', '300', '', ''])
        ->and($rows)->toContain(['Reactions', '30', '', ''])
        ->and($rows)->toContain(['Channel', 'Network', 'Posts', 'Reactions', 'Comments', 'Eng. rate', 'Reposts', 'Impressions', 'Clicks', 'Views', 'Shares', 'Saves', 'Follows gained from posts', 'Reach', 'Watch time (min)', 'Avg. watch time (sec)'])
        ->and($rows)->toContain(['alpha', 'Instagram', '1', '10', '1', '', '4', '100', '', '', '', '', '', '', '', ''])
        ->and($rows)->toContain(['bravo', 'Facebook Page', '1', '20', '1', '', '', '200', '7', '', '', '', '', '', '', ''])
        ->and($rows)->toContain(['alpha', 'Instagram', '100', ''])
        ->and($rows)->toContain(['Published at', 'Channel', 'Network', 'Type', 'Text', 'Link', 'Reactions', 'Comments', 'Eng. rate', 'Reposts', 'Impressions', 'Clicks', 'Views', 'Shares', 'Saves', 'Reach']);

    $posts = array_values(array_filter($rows, fn (array $row): bool => in_array(data_get($row, 4), ['alpha-reel', 'bravo-post'], true)));

    expect($posts)->toHaveCount(2)
        ->and(data_get($posts, '0.0'))->toBe('2026-09-16 09:30')
        ->and(data_get($posts, '0.4'))->toBe('bravo-post')
        ->and(data_get($posts, '0.5'))->toBe('https://example.com/bravo-post')
        ->and(data_get($posts, '0.11'))->toBe('7')
        ->and(data_get($posts, '1.4'))->toBe('alpha-reel')
        ->and(data_get($posts, '1.9'))->toBe('4');
});

test('the Markdown export renders each section as a table', function () {
    $response = $this->actingAs($this->user)->get(insightsExportRoute('md'));

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertDownload('trypost-insights-2026-09-30.md');

    $content = $response->streamedContent();

    expect($content)->toStartWith("# Insights\n\n- **Range:** 2026-09-01 – 2026-09-30\n")
        ->toContain("\n## Summary\n\n| Metric | Value | Previous period | Change |\n| --- | --- | --- | --- |\n| Posts | 2 | 0 | — |\n")
        ->toContain("\n## Performance\n")
        ->toContain('| alpha | Instagram | 1 | 10 | 1 | — | 4 | 100 |')
        ->toContain("\n## Followers\n")
        ->toContain("\n## Posts\n")
        ->toContain('| 2026-09-16 09:30 | bravo | Facebook Page |');
});

test('the export applies the channel and label filters of the page', function () {
    $channelOnly = $this->actingAs($this->user)
        ->get(insightsExportRoute('md', ['channels' => [$this->facebook->id]]))
        ->assertOk()
        ->streamedContent();

    expect($channelOnly)->toContain('bravo-post')
        ->not->toContain('alpha-reel')
        ->not->toContain('alpha');

    $labelOnly = $this->actingAs($this->user)
        ->get(insightsExportRoute('md', ['labels' => [$this->label->id]]))
        ->assertOk()
        ->streamedContent();

    expect($labelOnly)->toContain('alpha-reel')
        ->not->toContain('bravo-post');
});

test('the export never leaks another workspace and ignores foreign channel ids', function () {
    $foreignWorkspace = Workspace::factory()->create();
    $foreign = SocialAccount::factory()->create(['workspace_id' => $foreignWorkspace->id, 'platform' => Platform::Instagram, 'username' => 'outsider']);
    ($this->publish)($foreign, 99, 'foreign-secret');

    $content = $this->actingAs($this->user)
        ->get(insightsExportRoute('csv', ['channels' => [$foreign->id]]))
        ->assertOk()
        ->streamedContent();

    expect($content)->not->toContain('foreign-secret')
        ->not->toContain('outsider')
        ->toContain('alpha-reel')
        ->toContain('bravo-post');
});

test('the export validates the range like the page and only serves known formats', function () {
    $this->get(insightsExportRoute('csv'))->assertRedirect(route('login'));

    $this->actingAs($this->user)
        ->get(route('app.insights.download', ['format' => 'csv', 'range' => 'custom', 'start' => 'nonsense', 'end' => '2026-09-30']))
        ->assertSessionHasErrors('start');

    $this->actingAs($this->user)->get('/insights/download/xlsx')->assertNotFound();
});

test('the export translates headers into the user language', function () {
    $this->user->update(['locale' => Locale::PortugueseBrazil]);

    $rows = insightsCsvRows($this->actingAs($this->user)->get(insightsExportRoute('csv'))->assertOk()->streamedContent());

    expect($rows)->toContain(['Métrica', 'Valor', 'Período anterior', 'Variação'])
        ->and($rows)->toContain(['Publicado em', 'Canal', 'Rede', 'Tipo', 'Texto', 'Link', 'Reações', 'Comentários', 'Engajamento', 'Repostagens', 'Impressões', 'Cliques', 'Visualizações', 'Compartilhamentos', 'Salvamentos', 'Alcance']);
});

test('spreadsheet formulas in post text are neutralized in the CSV', function () {
    ($this->publish)($this->instagram, 1, '=HYPERLINK("https://evil.test")');

    $content = $this->actingAs($this->user)->get(insightsExportRoute('csv'))->assertOk()->streamedContent();

    expect($content)->toContain("'=HYPERLINK")
        ->not->toContain(',=HYPERLINK');
});

test('the export reads posts without one query per post', function () {
    $count = function (): int {
        $this->actingAs($this->user->fresh());
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(insightsExportRoute('csv'))->assertOk()->streamedContent();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $before = $count();

    foreach (range(1, 5) as $index) {
        ($this->publish)($this->facebook, $index, "extra-{$index}", null, "2026-09-1{$index} 08:00:00");
    }

    expect($count())->toBe($before);
});

test('the insights page shares the sync cadence and the extended performance metrics', function () {
    $this->actingAs($this->user)
        ->get(route('app.insights', ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/Index')
            ->where('sync', SyncCadence::toArray())
            ->where('sync.metrics_days', 30)
            ->where('sync.x_metrics_days', 20)
            ->where('report.performance.0.username', 'bravo')
            ->where('report.performance.0.clicks.value', 7)
            ->where('report.performance.0.impressions.value', 200)
            ->where('report.performance.1.reposts.value', 4)
            ->where('report.performance.1.clicks.value', null)
            ->etc());
});

test('the sync cadence follows the discovery settings the scheduler uses', function () {
    config()->set('trypost.analytics.discovery_interval_hours', 6);
    config()->set('trypost.analytics.x_discovery_interval_hours', 48);

    expect(SyncCadence::toArray())->toBe([
        'discovery_hours' => 6,
        'x_discovery_hours' => 24,
        'metrics_days' => 30,
        'x_metrics_days' => 20,
    ]);
});

test('the export of a workspace without connected channels has no past analytics', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => null,
        'social_account_key' => (string) Str::uuid(),
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => 'disconnected',
        'date' => '2026-09-20',
        'followers_count' => 4242,
    ]);

    $response = $this->actingAs($user)
        ->get(route('app.insights.download', ['format' => 'csv', 'start' => '2026-01-01', 'end' => '2026-12-31']));

    $response->assertOk();
    expect($response->streamedContent())->not->toContain('4242');
});
