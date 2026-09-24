<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Enums\UserWorkspace\Role;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;

test('imported Reel detail shows origin and watch time without publishing actions', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'origin' => PublicationOrigin::External,
        'content_type' => PublicationContentType::Reel,
        'excerpt' => 'Behind the scenes',
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'reactions_count' => 27,
        'watch_time_milliseconds' => 4042104000,
        'metrics' => [
            'reactions' => ['value' => 27, 'unit' => 'count', 'availability' => 'available'],
            'watch_time_milliseconds' => ['value' => 4042104000, 'unit' => 'milliseconds', 'availability' => 'available'],
        ],
    ]);
    Vite::useHotFile(storage_path('framework/testing/publication-analytics-no-hot'));
    $this->actingAs($user);

    $page = visit(route('app.analytics.publications.show', $publication));

    $page->assertScript('document.querySelector("[data-testid=analytics-publication-header]")?.innerText.includes("Published on Instagram")', true)
        ->assertScript('document.querySelector("[data-testid=analytics-publication-excerpt]")?.innerText.includes("Behind the scenes")', true)
        ->assertScript('document.querySelector("[data-testid=analytics-metric-watch_time_milliseconds]")?.innerText.includes("67.4K min")', true)
        ->assertScript('document.querySelector("[data-testid=analytics-metric-watch_time_milliseconds]")?.innerText.includes("67368.4 min")', false)
        ->assertMissing('@edit-publication')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('publication detail translates metric labels, time basis, content type and numbers', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create(['locale' => Locale::PortugueseBrazil]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'origin' => PublicationOrigin::External,
        'content_type' => PublicationContentType::Reel,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'metrics' => [
            'watch_time_milliseconds' => [
                'value' => 4042104000,
                'unit' => 'milliseconds',
                'availability' => 'available',
                'time_basis' => 'lifetime',
            ],
            'engagement_rate' => [
                'value' => 12.5,
                'unit' => 'percent',
                'availability' => 'available',
                'time_basis' => 'lifetime',
            ],
        ],
    ]);
    Vite::useHotFile(storage_path('framework/testing/publication-analytics-no-hot'));
    $this->actingAs($user);

    $page = visit(route('app.analytics.publications.show', $publication));

    $page->assertScript('document.querySelector("[data-testid=analytics-publication-header]")?.innerText.includes("Reels")', true)
        ->assertScript('document.querySelector("[data-testid=analytics-metric-engagement_rate]")?.innerText.includes("Taxa de engajamento")', true)
        ->assertScript('document.querySelector("[data-testid=analytics-metric-engagement_rate]")?.innerText.includes("12,5%")', true)
        ->assertScript('document.querySelector("[data-testid=analytics-metric-engagement_rate]")?.getAttribute("title")', 'Desde a publicação')
        ->assertScript('document.title.includes("Analytics do Instagram")', true)
        ->assertScript('document.querySelector("[data-testid=analytics-metric-watch_time_milliseconds]")?.innerText.includes("67,4\\u00a0mil min")', true)
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
