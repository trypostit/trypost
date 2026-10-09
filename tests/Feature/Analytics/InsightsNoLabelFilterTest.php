<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('the workspace insights page ignores label parameters and offers no label filter', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);

    foreach ([$label, null] as $attached) {
        $post = Post::factory()->forAccount($account)->published()->create(['user_id' => $user->id]);

        if ($attached !== null) {
            $post->labels()->attach($attached);
        }

        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $workspace->id,
            'social_account_id' => $account->id,
            'social_account_key' => $account->id,
            'post_id' => $post->id,
            'platform' => Platform::Instagram,
            'provider_published_at' => CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => '2026-09-15', 'reactions_count' => 10]);
    }

    $this->actingAs($user)
        ->get(route('app.insights', ['range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30', 'labels' => [$label->id], 'untagged' => 1]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/Index')
            ->missing('labels')
            ->missing('report.filters.labels')
            ->missing('report.filters.untagged')
            ->where('report.summary.posts.value', 2)
            ->etc());
});
