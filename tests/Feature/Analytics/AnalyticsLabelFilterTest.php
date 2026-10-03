<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    $this->campaign = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Campaign']);
    $this->other = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Other']);

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->account->id,
        'social_account_key' => $this->account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $this->account->platform_user_id,
        'date' => '2026-09-15',
        'followers_count' => 500,
    ]);

    $this->publish = function (?WorkspaceLabel $label, int $reactions): AnalyticsPublication {
        $post = Post::factory()->published()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

        if ($label !== null) {
            $post->labels()->attach($label);
        }

        $destination = PostPlatform::factory()->instagram()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $this->account->id,
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->account->id,
            'social_account_key' => $this->account->id,
            'post_platform_id' => $destination->id,
            'platform' => Platform::Instagram,
            'provider_published_at' => CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => '2026-09-15',
            'reactions_count' => $reactions,
            'comments_count' => 1,
        ]);

        return $publication;
    };
});

function analyticsLabelFilterRoute(array $labels = []): string
{
    return route('app.insights', array_filter([
        'range' => 'custom',
        'start' => '2026-09-01',
        'end' => '2026-09-30',
        'labels' => $labels,
    ]));
}

test('label filter restricts post metrics and lists to posts carrying any selected label', function () {
    $tagged = ($this->publish)($this->campaign, 10);
    $otherTagged = ($this->publish)($this->other, 20);
    $untagged = ($this->publish)(null, 40);
    AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->account->id,
        'social_account_key' => $this->account->id,
        'post_platform_id' => null,
        'platform' => Platform::Instagram,
        'provider_published_at' => CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'),
    ]);

    $this->actingAs($this->user)
        ->get(analyticsLabelFilterRoute([$this->campaign->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/Index')
            ->where('report.filters.labels', [$this->campaign->id])
            ->has('labels', 2)
            ->where('report.summary.posts.value', 1)
            ->where('report.summary.reactions.value', 10)
            ->where('report.summary.followers.value', 500)
            ->has('report.top_posts.reactions', 1)
            ->where('report.top_posts.reactions.0.id', $tagged->id)
            ->where('report.performance.0.posts.value', 1)
            ->where('report.posts.buckets', fn ($buckets) => collect($buckets)->sum('total') === 1)
            ->etc());

    $this->actingAs($this->user)
        ->get(analyticsLabelFilterRoute([$this->campaign->id, $this->other->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 2)
            ->where('report.summary.reactions.value', 30)
            ->where('report.top_posts.reactions', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all()
                === collect([$tagged->id, $otherTagged->id])->sort()->values()->all())
            ->etc());

    $this->actingAs($this->user)
        ->get(analyticsLabelFilterRoute())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.labels', [])
            ->where('report.summary.posts.value', 4)
            ->where('report.summary.reactions.value', 70)
            ->where('report.top_posts.reactions.0.id', $untagged->id)
            ->etc());
});

test('invalid, foreign and deleted label ids are ignored', function () {
    ($this->publish)($this->campaign, 10);
    ($this->publish)(null, 40);
    $foreign = WorkspaceLabel::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);
    $deleted = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    $deleted->delete();

    $this->actingAs($this->user)
        ->get(analyticsLabelFilterRoute(['not-a-uuid', $foreign->id, $deleted->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.labels', [])
            ->has('labels', 2)
            ->where('report.summary.posts.value', 2)
            ->etc());

    $this->actingAs($this->user)
        ->get(analyticsLabelFilterRoute(['not-a-uuid', $foreign->id, $this->campaign->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.labels', [$this->campaign->id])
            ->where('report.summary.posts.value', 1)
            ->etc());
});
