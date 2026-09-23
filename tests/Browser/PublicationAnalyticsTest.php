<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
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
        'analytics_publication_id' => $publication->id,
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

    $page->assertSee('Published on Instagram')
        ->assertSee('Behind the scenes')
        ->assertSee('Watch time')
        ->assertSee('67.4K min')
        ->assertDontSee('67368.4 min')
        ->assertMissing('@edit-publication')
        ->assertNoJavaScriptErrors()
        ->assertNoConsoleLogs();
});
