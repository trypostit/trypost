<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationOrigin;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
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
    $post = Post::factory()->forAccount($account)->published()->create(['user_id' => $user->id]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Pinterest,
        'network' => Platform::Pinterest->network(),
        'origin' => PublicationOrigin::TryPost,
        'post_id' => $post->id,
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
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('posts.data.0.metrics.metrics.saves.value', 6)
                ->etc())
            ->etc());

    Http::assertNothingSent();
});

test('a publication without a TryPost post returns its details and latest metrics', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'origin' => PublicationOrigin::External,
        'excerpt' => 'Posted straight on Instagram',
        'permalink' => 'https://www.instagram.com/p/abc/',
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'reactions_count' => 12,
    ]);

    $this->actingAs($user)
        ->getJson(route('app.insights.publications.details', $publication->id))
        ->assertOk()
        ->assertJsonPath('publication.id', $publication->id)
        ->assertJsonPath('publication.excerpt', 'Posted straight on Instagram')
        ->assertJsonPath('publication.permalink', 'https://www.instagram.com/p/abc/')
        ->assertJsonPath('available', true);
});

test('a publication of another workspace is not found', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $foreign = AnalyticsPublication::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($user)
        ->getJson(route('app.insights.publications.details', $foreign->id))
        ->assertNotFound();
});
