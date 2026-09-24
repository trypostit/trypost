<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
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
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
});

test('imported publication detail reads persisted Reel metrics and identifies its origin', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
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
        'analytics_publication_id' => $publication->id,
        'watch_time_milliseconds' => 185000,
        'metrics' => [
            'watch_time_milliseconds' => ['value' => 185000, 'unit' => 'milliseconds', 'availability' => 'available'],
        ],
    ]);
    Http::fake();

    $this->actingAs($user)
        ->get(route('app.analytics.publications.show', $publication))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics/Publications/Show')
            ->where('detail.publication.origin', 'external')
            ->where('detail.publication.content_type', 'reel')
            ->where('detail.metrics.watch_time_milliseconds.value', 185000)
            ->etc());

    Http::assertNothingSent();
});

test('external publication detail is isolated by immutable workspace id and excludes LinkedIn', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $foreign = AnalyticsPublication::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $linkedIn = AnalyticsPublication::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn, 'network' => Platform::LinkedIn->network()]);

    $this->actingAs($user)
        ->get(route('app.analytics.publications.show', $foreign))
        ->assertNotFound();
    $this->actingAs($user)
        ->get(route('app.analytics.publications.show', $linkedIn))
        ->assertNotFound();
});

test('post analytics URL uses the post id and selects the requested destination', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $post = Post::factory()->published()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $otherPost = Post::factory()->published()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $pinterest = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Pinterest]);
    $firstDestination = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);
    $secondDestination = PostPlatform::factory()->pinterest()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $pinterest->id,
    ]);
    $otherDestination = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => $otherPost->id,
        'social_account_id' => $account->id,
    ]);
    $first = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $firstDestination->id,
        'platform' => Platform::Instagram,
    ]);
    $second = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $pinterest->id,
        'social_account_key' => $pinterest->id,
        'post_platform_id' => $secondDestination->id,
        'platform' => Platform::Pinterest,
        'network' => Platform::Pinterest->network(),
    ]);
    $other = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $otherDestination->id,
        'platform' => Platform::Instagram,
    ]);

    expect(route('app.analytics.show', $post))->toEndWith("/analytics/{$post->id}");

    $this->actingAs($user)
        ->get(route('app.analytics.show', ['post' => $post->id, 'publication' => $second->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('analytics/Publications/Show')
            ->where('detail.publication.id', $second->id)
            ->etc());

    $this->actingAs($user)
        ->get(route('app.analytics.show', ['post' => $post->id, 'publication' => $other->id]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('app.analytics.show', $first->id))
        ->assertNotFound();
});

test('post analytics detail does not cross workspaces', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    $foreignPost = Post::factory()->published()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($user)
        ->get(route('app.analytics.show', $foreignPost))
        ->assertNotFound();
});

test('TryPost post page receives its latest saved post metrics as a page prop', function () {
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
        'analytics_publication_id' => $publication->id,
        'saves_count' => 6,
        'metrics' => ['saves' => ['value' => 6, 'unit' => 'count', 'availability' => 'available']],
    ]);
    Http::fake();

    $this->actingAs($user)
        ->get(route('app.posts.show', $post))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('posts/Show')
            ->where("postMetrics.{$destination->id}.metrics.saves.value", 6)
            ->etc());

    Http::assertNothingSent();
});
