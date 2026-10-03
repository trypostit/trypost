<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationOrigin;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

test('the standalone post and publication insights pages are gone', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $publication = AnalyticsPublication::factory()->create(['workspace_id' => $workspace->id]);

    expect(Route::has('app.insights.show'))->toBeFalse()
        ->and(Route::has('app.insights.publications.show'))->toBeFalse();

    $this->actingAs($user)->get("/insights/{$post->id}")->assertNotFound();
    $this->actingAs($user)->get("/insights/{$post->id}?publication={$publication->id}")->assertNotFound();
    $this->actingAs($user)->get("/insights/publications/{$publication->id}")->assertNotFound();
});

test('the post details deep link receives the latest saved post metrics', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Pinterest]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $destination = PostPlatform::factory()->pinterest()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Pinterest,
        'network' => Platform::Pinterest->network(),
        'origin' => PublicationOrigin::TryPost,
        'post_platform_id' => $destination->id,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'saves_count' => 6,
        'metrics' => ['saves' => ['value' => 6, 'unit' => 'count', 'availability' => 'available']],
    ]);
    Http::fake();

    $this->actingAs($user)
        ->get(route('app.posts.index', ['post' => $post->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('publish/Index')
            ->where('openPostDetailsId', $post->id)
            ->where("posts.data.0.metrics.{$destination->id}.metrics.saves.value", 6)
            ->etc());

    Http::assertNothingSent();
});
