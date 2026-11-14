<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->account = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    $this->label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Launch']);
    $this->day = now('UTC')->subDays(5)->startOfDay();
    $this->range = ['range' => 'custom', 'start' => $this->day->subDays(5)->toDateString(), 'end' => $this->day->addDays(2)->toDateString()];

    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->account->id,
        'social_account_key' => $this->account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $this->account->platform_user_id,
        'date' => $this->day->toDateString(),
        'followers_count' => 500,
    ]);

    foreach ([[$this->label, 10], [null, 4]] as [$attached, $reactions]) {
        $post = Post::factory()->forAccount($this->account)->published()->create(['user_id' => $this->user->id]);

        if ($attached !== null) {
            $post->labels()->attach($attached);
        }

        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->account->id,
            'social_account_key' => $this->account->id,
            'post_id' => $post->id,
            'platform' => Platform::Instagram,
            'provider_published_at' => $this->day->setTime(12, 0),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create(['publication_id' => $publication->id, 'date' => $this->day->toDateString(), 'reactions_count' => $reactions]);
    }
});

test('the workspace insights page offers the labels and counts only the posts of the picked ones', function (Closure $query, int $posts, int $reactions) {
    $filters = $query($this->label);

    $this->actingAs($this->user)
        ->get(route('app.insights', [...$this->range, ...$filters]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/Index')
            ->where('labels.0.id', $this->label->id)
            ->where('labels.0.name', 'Launch')
            ->where('report.filters.labels', data_get($filters, 'labels', []))
            ->where('report.filters.untagged', (bool) data_get($filters, 'untagged', false))
            ->where('report.summary.posts.value', $posts)
            ->where('report.summary.reactions.value', $reactions)
            ->where('report.summary.followers.value', 500)
            ->etc());
})->with([
    'no label filter' => [fn (WorkspaceLabel $label): array => [], 2, 14],
    'one label' => [fn (WorkspaceLabel $label): array => ['labels' => [$label->id]], 1, 10],
    'untagged' => [fn (WorkspaceLabel $label): array => ['untagged' => 1], 1, 4],
    'the label or untagged' => [fn (WorkspaceLabel $label): array => ['labels' => [$label->id], 'untagged' => 1], 2, 14],
]);

test('the workspace insights page refuses a label of another workspace', function () {
    $this->actingAs($this->user)
        ->getJson(route('app.insights', [...$this->range, 'labels' => [WorkspaceLabel::factory()->create()->id]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('labels.0');
});

test('the insights export counts only the posts of the picked labels', function () {
    $content = $this->actingAs($this->user)
        ->get(route('app.insights.download', ['format' => 'csv', ...$this->range, 'labels' => [$this->label->id]]))
        ->assertOk()
        ->streamedContent();
    $rows = collect(preg_split('/\r?\n/', trim(ltrim($content, "\u{FEFF}"))))
        ->map(fn (string $line): array => str_getcsv($line, escape: ''))
        ->all();

    expect($rows)->toContain(['Posts', '1', '0', ''])
        ->and($rows)->toContain(['Reactions', '10', '', '']);
});
