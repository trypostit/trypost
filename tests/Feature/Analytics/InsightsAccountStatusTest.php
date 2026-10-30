<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

test('insights rows carry the live connection status and the top post avatar of each channel', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram, 'status' => Status::TokenExpired]);

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $account->platform_user_id,
        'date' => '2026-09-15',
        'followers_count' => 500,
    ]);
    $post = Post::factory()->forAccount($account)->published()->create(['user_id' => $user->id]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_id' => $post->id,
        'platform' => Platform::Instagram,
        'account_avatar_url' => 'https://example.test/avatar.png',
        'provider_published_at' => CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-15',
        'reactions_count' => 10,
        'comments_count' => 1,
    ]);

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.performance.0.status', Status::TokenExpired->value)
            ->where('report.posts.accounts.0.status', Status::TokenExpired->value)
            ->where('report.followers.accounts.0.status', Status::TokenExpired->value)
            ->where('channelOptions.0.status', Status::TokenExpired->value)
            ->where('report.top_posts.reactions.0.status', Status::TokenExpired->value)
            ->where('report.top_posts.reactions.0.avatar_url', 'https://example.test/avatar.png')
            ->etc());

    $account->forceDelete();

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('channelOptions', [])
            ->where('report.performance', [])
            ->where('report.bounds.min', null)
            ->etc());
});

test('insights rows carry the verified badge of each channel like the sidebar', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $verified = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id, 'meta' => ['x_verified_type' => 'blue']]);

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $verified->id,
        'social_account_key' => $verified->id,
        'platform' => Platform::X,
        'network' => Platform::X->network(),
        'platform_user_id' => $verified->platform_user_id,
        'date' => '2026-09-15',
        'followers_count' => 500,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $verified->id,
        'social_account_key' => $verified->id,
        'platform' => Platform::X,
        'provider_published_at' => CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => '2026-09-15',
        'reactions_count' => 10,
    ]);

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.performance.0.verified_badge', 'blue')
            ->where('report.posts.accounts.0.verified_badge', 'blue')
            ->where('report.followers.accounts.0.verified_badge', 'blue')
            ->where('report.top_posts.reactions.0.verified_badge', 'blue')
            ->etc());

    $verified->update(['meta' => []]);

    $this->actingAs($user)
        ->get(route('app.insights', ['start' => '2026-09-01', 'end' => '2026-09-30']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.performance.0.verified_badge', null)
            ->etc());
});
