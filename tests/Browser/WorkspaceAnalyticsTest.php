<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Vite;

test('workspace dashboard separates accounts and switches chart and top-post modes', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    foreach ([['first', 120], ['second', 30]] as [$username, $followers]) {
        $account = SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => Platform::Instagram,
            'username' => $username,
        ]);
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'platform_user_id' => $account->platform_user_id,
            'account_username' => $username,
            'snapshot_date' => '2026-09-23',
            'followers_count' => $followers,
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'platform' => Platform::Instagram,
            'network' => Platform::Instagram->network(),
            'account_username' => $username,
            'provider_published_at' => '2026-09-12 10:00:00',
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'analytics_publication_id' => $publication->id,
            'reactions_count' => $followers,
            'comments_count' => 1,
        ]);
    }

    $this->actingAs($user);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));
    $page = visit(route('app.analytics'));
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.body.innerText.includes('Total Followers')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertSee('Total Followers')
        ->assertSee('@first')
        ->assertSee('@second')
        ->assertSee('Top 5 Posts')
        ->assertSee('Performance')
        ->click('@followers-growth')
        ->click('@posts-stacked')
        ->click('@top-comments')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});

test('workspace dashboard explains the empty state without inventing follower totals', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    Vite::useHotFile(storage_path('framework/testing/workspace-analytics-no-hot'));

    $this->actingAs($user);
    $page = visit(route('app.analytics'));

    $page->assertSee('Your analytics history is being prepared')
        ->assertMissing('@analytics-summary')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
