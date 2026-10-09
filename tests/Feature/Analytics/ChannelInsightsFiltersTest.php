<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\PostPlatform\ContentType;
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

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->travelTo('2026-09-30 12:00 UTC');

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->instagram = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
    ]);
    $this->label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->reel = filteredInsightsPublication($this, '2026-09-20 10:00:00', 10, ContentType::InstagramReel, $this->label);
    $this->feed = filteredInsightsPublication($this, '2026-09-18 10:00:00', 5, ContentType::InstagramFeed);
    $this->story = filteredInsightsPublication($this, '2026-09-16 10:00:00', 3, publicationType: PublicationContentType::Story);
    $this->unknown = filteredInsightsPublication($this, '2026-09-14 10:00:00', 1, publicationType: PublicationContentType::Unknown);
    $this->previousFeed = filteredInsightsPublication($this, '2026-08-20 10:00:00', 7, ContentType::InstagramFeed, $this->label);
});

function filteredInsightsPublication(
    object $test,
    string $publishedAt,
    int $reactions,
    ?ContentType $contentType = null,
    ?WorkspaceLabel $label = null,
    PublicationContentType $publicationType = PublicationContentType::Image,
): AnalyticsPublication {
    $post = null;

    if ($contentType !== null) {
        $post = Post::factory()->forAccount($test->instagram, $contentType)->published()->create(['user_id' => $test->user->id]);

        if ($label !== null) {
            $post->labels()->attach($label);
        }
    }

    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $test->workspace->id,
        'social_account_id' => $test->instagram->id,
        'social_account_key' => $test->instagram->id,
        'network' => $test->instagram->platform->network(),
        'platform_user_id' => $test->instagram->platform_user_id,
        'platform' => Platform::Instagram,
        'post_id' => $post?->id,
        'content_type' => $publicationType,
        'provider_published_at' => CarbonImmutable::parse($publishedAt, 'UTC'),
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => CarbonImmutable::parse($publishedAt, 'UTC')->toDateString(),
        'reactions_count' => $reactions,
    ]);

    return $publication;
}

/**
 * @param  array<string, mixed>  $query
 */
function filteredInsightsUrl(SocialAccount $account, array $query = []): string
{
    return route('app.channels.insights', ['account' => $account, 'range' => 'custom', 'start' => '2026-09-01', 'end' => '2026-09-30', ...$query]);
}

test('the page offers the workspace labels and only this platform post types', function () {
    WorkspaceLabel::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('labels', 1)
            ->where('labels.0.id', $this->label->id)
            ->where('contentTypes', [
                ['value' => 'instagram_feed', 'label' => 'Feed Post'],
                ['value' => 'instagram_reel', 'label' => 'Reel'],
                ['value' => 'instagram_story', 'label' => 'Story'],
            ])
            ->where('filters.labels', [])
            ->where('filters.untagged', false)
            ->where('filters.types', [])
            ->where('report.summary.posts.value', 4)
            ->where('report.summary.reactions.value', 19)
            ->etc());
});

test('a label narrows the totals, the comparison period and the post list', function () {
    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram, ['labels' => [$this->label->id]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.labels', [$this->label->id])
            ->where('report.summary.posts.value', 1)
            ->where('report.summary.posts.previous', 1)
            ->where('report.summary.reactions.value', 10)
            ->where('report.summary.reactions.previous', 7)
            ->where('report.posts.buckets', fn ($buckets): bool => collect($buckets)->sum('total') === 1)
            ->has('publications.data', 1)
            ->where('publications.data.0.id', $this->reel->id)
            ->etc());
});

test('untagged counts posts without labels and publications made outside TryPost', function () {
    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram, ['untagged' => '1']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.untagged', true)
            ->where('report.summary.posts.value', 3)
            ->where('report.summary.posts.previous', 0)
            ->where('report.summary.reactions.value', 9)
            ->has('publications.data', 3)
            ->etc());

    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram, ['labels' => [$this->label->id], 'untagged' => '1']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 4)
            ->etc());
});

test('post types match TryPost posts by their content type and imported ones by their publication type', function (string $type, string $expected, int $posts, int $previous) {
    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram, ['types' => [$type]]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.types', [$type])
            ->where('report.summary.posts.value', $posts)
            ->where('report.summary.posts.previous', $previous)
            ->has('publications.data', $posts)
            ->where('publications.data.0.id', $this->{$expected}->id)
            ->etc());
})->with([
    'reel' => ['instagram_reel', 'reel', 1, 0],
    'imported story' => ['instagram_story', 'story', 1, 0],
    'feed excludes unknown publications' => ['instagram_feed', 'feed', 1, 1],
]);

test('labels and post types combine', function () {
    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram, ['labels' => [$this->label->id], 'types' => ['instagram_feed', 'instagram_story']]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 0)
            ->where('report.summary.posts.previous', 1)
            ->where('report.summary.reactions.previous', 7)
            ->has('publications.data', 0)
            ->etc());
});

test('a label of another workspace or a deleted label is refused', function () {
    $foreign = WorkspaceLabel::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $deleted = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $deleted->delete();

    foreach ([$foreign, $deleted] as $label) {
        $this->actingAs($this->user)
            ->get(filteredInsightsUrl($this->instagram, ['labels' => [$label->id]]))
            ->assertSessionHasErrors('labels.0');
    }

    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram, ['labels' => ['not-a-uuid']]))
        ->assertSessionHasErrors('labels.0');
});

test('a post type of another platform is refused', function () {
    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram, ['types' => ['tiktok_video']]))
        ->assertSessionHasErrors('types.0');

    $this->actingAs($this->user)
        ->get(filteredInsightsUrl($this->instagram, ['types' => ['instagram_reel']]))
        ->assertSessionHasNoErrors();
});

test('the channel export honours the filters', function () {
    $content = $this->actingAs($this->user)
        ->get(route('app.channels.insights.download', [
            'account' => $this->instagram,
            'format' => 'csv',
            'range' => 'custom',
            'start' => '2026-09-01',
            'end' => '2026-09-30',
            'types' => ['instagram_story'],
        ]))
        ->assertOk()
        ->streamedContent();

    expect($content)->toContain($this->story->permalink)
        ->not->toContain($this->reel->permalink)
        ->not->toContain($this->feed->permalink);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights.download', ['account' => $this->instagram, 'format' => 'csv', 'types' => ['tiktok_video']]))
        ->assertSessionHasErrors('types.0');
});

test('the channel export is not available for another workspace channel', function () {
    $other = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    $this->actingAs($this->user)
        ->get(route('app.channels.insights.download', ['account' => $other, 'format' => 'csv']))
        ->assertNotFound();
});
