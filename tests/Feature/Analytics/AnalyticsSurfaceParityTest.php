<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Analytics\GetAnalyticsPublicationTool;
use App\Mcp\Tools\Analytics\GetAnalyticsReportTool;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

test('web API and MCP expose the same workspace analytics report and date bounds', function () {
    $access = createApiTestToken();
    $workspace = $access['workspace'];
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'date' => '2026-09-20',
        'followers_count' => 123,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'origin' => PublicationOrigin::External,
        'provider_published_at' => '2026-09-20 10:00:00',
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-20',
        'reactions_count' => 7,
        'comments_count' => 2,
    ]);
    $foreign = AnalyticsPublication::factory()->create([
        'workspace_id' => Workspace::factory()->create()->id,
        'platform' => Platform::Instagram,
        'provider_published_at' => '2026-09-20 10:00:00',
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $foreign->id,
        'date' => '2026-09-20',
        'reactions_count' => 999,
    ]);
    Http::fake();
    $selected = ['start' => '2026-09-01', 'end' => '2026-09-30'];

    $this->actingAs($access['user'])
        ->get(route('app.insights', $selected))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/Index')
            ->where('report.range.start', '2026-09-20')
            ->where('report.range.end', '2026-09-20')
            ->where('report.summary.followers.value', 123)
            ->where('report.summary.posts.value', 1)
            ->where('report.top_posts.reactions.0.id', $publication->id)
            ->etc());

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.index', $selected))
        ->assertOk()
        ->assertJsonPath('bounds.min', '2026-09-20')
        ->assertJsonPath('range.start', '2026-09-20')
        ->assertJsonPath('range.end', '2026-09-20')
        ->assertJsonPath('summary.followers.value', 123)
        ->assertJsonPath('summary.posts.value', 1)
        ->assertJsonPath('summary.reactions.value', 7)
        ->assertJsonPath('summary.comments.value', 2)
        ->assertJsonPath('top_posts.reactions.0.id', $publication->id)
        ->assertJsonCount(1, 'performance')
        ->assertJsonStructure(['bounds', 'range', 'previous_range', 'summary', 'followers', 'posts', 'top_posts', 'performance', 'coverage']);

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsReportTool::class, $selected)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('range.start', '2026-09-20')
            ->where('summary.followers.value', 123)
            ->where('summary.posts.value', 1)
            ->where('top_posts.reactions.0.id', $publication->id)
            ->has('bounds')
            ->has('followers')
            ->has('posts')
            ->has('performance')
            ->has('coverage')
            ->etc());

    Http::assertNothingSent();
});

test('API and MCP validate analytics date selections like the web', function () {
    $access = createApiTestToken();

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.index', ['start' => 'not-a-date']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start');

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.index', ['start' => '2026-09-21', 'end' => '2026-09-20']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end');

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsReportTool::class, ['start' => 'not-a-date'])
        ->assertHasErrors();

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsReportTool::class, ['start' => '2026-09-21', 'end' => '2026-09-20'])
        ->assertHasErrors();
});

test('analytics API requires authentication and MCP denies a foreign workspace', function () {
    $access = createApiTestToken();
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $access['workspace']->id]);

    $this->getJson(route('api.analytics.index'))->assertUnauthorized();
    $this->getJson(route('api.analytics.publications.show', $publication))->assertUnauthorized();

    $outsider = User::factory()->create(['current_workspace_id' => $access['workspace']->id]);

    TryPostServer::actingAs($outsider)
        ->tool(GetAnalyticsReportTool::class, [])
        ->assertHasErrors(['Not authorized to view workspace analytics.']);

    TryPostServer::actingAs($outsider)
        ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => $publication->id])
        ->assertHasErrors(['Not authorized to view workspace analytics.']);
});

test('API and MCP expose imported publication metrics and isolate workspaces', function () {
    $access = createApiTestToken();
    $workspace = $access['workspace'];
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
        'origin' => PublicationOrigin::External,
        'content_type' => PublicationContentType::Reel,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'saves_count' => 6,
        'watch_time_milliseconds' => 185000,
        'metrics' => [
            'saves' => ['value' => 6, 'unit' => 'count', 'availability' => 'available'],
            'watch_time_milliseconds' => ['value' => 185000, 'unit' => 'milliseconds', 'availability' => 'available'],
        ],
    ]);
    $foreign = AnalyticsPublication::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $excluded = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'network' => Platform::LinkedIn->network(),
    ]);
    Http::fake();

    $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
        ->getJson(route('api.analytics.publications.show', $publication))
        ->assertOk()
        ->assertJsonPath('publication.id', $publication->id)
        ->assertJsonPath('publication.origin', 'external')
        ->assertJsonPath('snapshot.saves_count', 6)
        ->assertJsonPath('snapshot.watch_time_milliseconds', 185000)
        ->assertJsonPath('metrics.saves.value', 6)
        ->assertJsonPath('metrics.watch_time_milliseconds.value', 185000);

    foreach ([$foreign, $excluded] as $hidden) {
        $this->withHeaders(['Authorization' => 'Bearer '.$access['plain_token']])
            ->getJson(route('api.analytics.publications.show', $hidden))
            ->assertNotFound();
    }

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => $publication->id])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json) => $json
            ->where('publication.id', $publication->id)
            ->where('publication.origin', 'external')
            ->where('snapshot.saves_count', 6)
            ->where('metrics.watch_time_milliseconds.value', 185000)
            ->etc());

    foreach ([$foreign, $excluded] as $hidden) {
        TryPostServer::actingAs($access['user'])
            ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => $hidden->id])
            ->assertHasErrors(['Publication not found.']);
    }

    TryPostServer::actingAs($access['user'])
        ->tool(GetAnalyticsPublicationTool::class, ['publication_id' => 'invalid'])
        ->assertHasErrors();

    Http::assertNothingSent();
});

test('the analytics API resolves a range preset in the user time zone without clamping', function () {
    $this->travelTo('2026-09-30 12:00 UTC');
    $access = createApiTestToken();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $access['workspace']->id]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $access['workspace']->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'date' => '2026-09-28',
        'followers_count' => 50,
    ]);

    $this->withHeaders(['Authorization' => "Bearer {$access['plain_token']}"])
        ->getJson(route('api.analytics.index', ['range' => '7d', 'start' => '', 'end' => '']))
        ->assertOk()
        ->assertJsonPath('range.start', '2026-09-24')
        ->assertJsonPath('range.end', '2026-09-30')
        ->assertJsonPath('previous_range.start', '2026-09-17')
        ->assertJsonPath('bounds.max', '2026-09-28');
});

test('the analytics API keeps the last 30 days of available data when no range is sent', function () {
    $this->travelTo('2026-09-30 12:00 UTC');
    $access = createApiTestToken();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $access['workspace']->id]);

    foreach (['2026-07-01', '2026-08-10'] as $date) {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $access['workspace']->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'platform' => $account->platform,
            'date' => $date,
            'followers_count' => 50,
        ]);
    }

    $this->withHeaders(['Authorization' => "Bearer {$access['plain_token']}"])
        ->getJson(route('api.analytics.index'))
        ->assertOk()
        ->assertJsonPath('range.start', '2026-07-12')
        ->assertJsonPath('range.end', '2026-08-10');

    $this->withHeaders(['Authorization' => "Bearer {$access['plain_token']}"])
        ->getJson(route('api.analytics.index', ['range' => '30d']))
        ->assertOk()
        ->assertJsonPath('range.start', '2026-09-01')
        ->assertJsonPath('range.end', '2026-09-30');
});
