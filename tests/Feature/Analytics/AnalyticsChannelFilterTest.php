<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->follower = function (SocialAccount $account, string $date, int $followers, ?string $key = null): void {
        AnalyticsAccountDailySnapshot::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $key ?? $account->id,
            'platform' => $account->platform,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'date' => $date,
            'followers_count' => $followers,
        ]);
    };

    $this->publish = function (SocialAccount $account, int $reactions, ?WorkspaceLabel $label = null, ?string $key = null, string $publishedAt = '2026-09-15 12:00:00'): AnalyticsPublication {
        $post = Post::factory()->published()->create(['workspace_id' => $account->workspace_id, 'user_id' => $this->user->id]);

        if ($label !== null) {
            $post->labels()->attach($label);
        }

        $destination = PostPlatform::factory()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
        ]);
        $publication = AnalyticsPublication::factory()->create([
            'workspace_id' => $account->workspace_id,
            'social_account_id' => $account->id,
            'social_account_key' => $key ?? $account->id,
            'post_platform_id' => $destination->id,
            'platform' => $account->platform,
            'network' => $account->platform->network(),
            'platform_user_id' => $account->platform_user_id,
            'provider_published_at' => CarbonImmutable::parse($publishedAt, 'UTC'),
        ]);
        AnalyticsPublicationDailySnapshot::factory()->create([
            'publication_id' => $publication->id,
            'date' => substr($publishedAt, 0, 10),
            'reactions_count' => $reactions,
            'comments_count' => 1,
        ]);

        return $publication;
    };

    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram, 'username' => 'alpha']);
    $this->facebook = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Facebook, 'username' => 'bravo']);
    $this->threads = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Threads, 'username' => 'charlie']);

    foreach ([[$this->instagram, 100, 10], [$this->facebook, 200, 20], [$this->threads, 300, 40]] as [$account, $followers, $reactions]) {
        ($this->follower)($account, '2026-09-01', $followers - 10);
        ($this->follower)($account, '2026-09-20', $followers);
        ($this->publish)($account, $reactions);
    }
});

function analyticsChannelFilterRoute(array $channels = [], array $labels = [], bool $untagged = false): string
{
    return route('app.insights', array_filter([
        'range' => 'custom',
        'start' => '2026-09-01',
        'end' => '2026-09-30',
        'channels' => $channels,
        'labels' => $labels,
        'untagged' => $untagged ? 1 : null,
    ]));
}

test('without a channel filter every channel is reported and offered as an option', function () {
    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('insights/Index')
            ->has('channelOptions', 3)
            ->where('channelOptions.0.analytics_key', fn (string $key): bool => in_array($key, [$this->instagram->id, $this->facebook->id, $this->threads->id], true))
            ->where('report.filters.channels', [])
            ->where('channels.0.scheduled_posts_count', fn ($count): bool => is_int($count))
            ->where('availableMetrics', null)
            ->where('report.summary.posts.value', 3)
            ->where('report.summary.reactions.value', 70)
            ->where('report.summary.followers.value', 600)
            ->has('report.performance', 3)
            ->has('report.followers.accounts', 3)
            ->etc());
});

test('a single channel scopes posts, followers, top posts, performance and coverage and exposes its metric set', function () {
    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([$this->facebook->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.channels', [$this->facebook->id])
            ->where('report.summary.posts.value', 1)
            ->where('report.summary.reactions.value', 20)
            ->where('report.summary.followers.value', 200)
            ->has('report.performance', 1)
            ->where('report.performance.0.social_account_key', $this->facebook->id)
            ->has('report.followers.accounts', 1)
            ->where('report.followers.accounts.0.social_account_key', $this->facebook->id)
            ->where('report.followers.total', 200)
            ->has('report.top_posts.reactions', 1)
            ->where('report.posts.accounts.0.social_account_key', $this->facebook->id)
            ->where('report.coverage', fn ($rows) => collect($rows)->every(fn (array $row): bool => $row['social_account_id'] === $this->facebook->id))
            ->where('availableMetrics', fn ($metrics) => collect($metrics)->contains('followers') && collect($metrics)->contains('reactions'))
            ->etc());
});

test('several channels are combined and the rest are left out', function () {
    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([$this->instagram->id, $this->threads->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.channels', fn ($ids) => collect($ids)->sort()->values()->all()
                === collect([$this->instagram->id, $this->threads->id])->sort()->values()->all())
            ->where('availableMetrics', null)
            ->where('report.summary.posts.value', 2)
            ->where('report.summary.reactions.value', 50)
            ->where('report.summary.followers.value', 400)
            ->where('report.performance', fn ($rows) => collect($rows)->pluck('social_account_key')->sort()->values()->all()
                === collect([$this->instagram->id, $this->threads->id])->sort()->values()->all())
            ->where('report.followers.series', fn ($series) => collect($series)->every(fn (array $point): bool => ! array_key_exists($this->facebook->id, $point['accounts'])))
            ->etc());
});

test('invalid and foreign channel ids are ignored', function () {
    $foreign = SocialAccount::factory()->create(['workspace_id' => Workspace::factory()->create()->id, 'platform' => Platform::Instagram]);
    ($this->publish)($foreign, 500);

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute(['not-a-uuid', $foreign->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.channels', [])
            ->has('channelOptions', 3)
            ->where('report.summary.posts.value', 3)
            ->where('report.summary.reactions.value', 70)
            ->etc());

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([$foreign->id, $this->instagram->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.channels', [$this->instagram->id])
            ->where('report.summary.posts.value', 1)
            ->where('report.summary.reactions.value', 10)
            ->etc());
});

test('channels and labels combine as an intersection while followers follow the channels only', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    ($this->publish)($this->instagram, 5, $label);
    ($this->publish)($this->facebook, 7, $label);

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([$this->instagram->id], [$label->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.channels', [$this->instagram->id])
            ->where('report.filters.labels', [$label->id])
            ->where('report.summary.posts.value', 1)
            ->where('report.summary.reactions.value', 5)
            ->where('report.summary.followers.value', 100)
            ->etc());
});

test('a reconnected channel matches the rows stored under its previous analytics key', function () {
    $previousKey = $this->instagram->id;
    $platformUserId = $this->instagram->platform_user_id;
    $this->instagram->delete();
    AnalyticsPublication::query()->where('social_account_key', $previousKey)->update(['social_account_id' => null]);
    AnalyticsAccountDailySnapshot::query()->where('social_account_key', $previousKey)->update(['social_account_id' => null]);
    $reconnected = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => $platformUserId,
    ]);

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([$reconnected->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.channels', [$reconnected->id])
            ->where('channelOptions', fn ($channels) => collect($channels)->firstWhere('id', $reconnected->id)['analytics_key'] === $previousKey)
            ->where('report.summary.posts.value', 1)
            ->where('report.summary.reactions.value', 10)
            ->where('report.followers.accounts.0.value', 100)
            ->etc());
});

test('previous-period values follow the channel filter, and the label filter leaves previous followers untouched', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);

    foreach ([[$this->instagram, 50, 1], [$this->facebook, 60, 2], [$this->threads, 70, 4]] as [$account, $followers, $reactions]) {
        ($this->follower)($account, '2026-08-20', $followers);
        ($this->publish)($account, $reactions, $account->is($this->threads) ? $label : null, null, '2026-08-15 12:00:00');
    }

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([$this->instagram->id, $this->threads->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.previous', 2)
            ->where('report.summary.reactions.previous', 5)
            ->where('report.summary.followers.previous', 120)
            ->where('report.summary.followers.change', 280)
            ->where('report.performance', fn ($rows) => collect($rows)->every(fn (array $row): bool => $row['posts']['previous'] === 1))
            ->etc());

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([], [$label->id]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 0)
            ->where('report.summary.posts.previous', 1)
            ->where('report.summary.reactions.previous', 4)
            ->where('report.summary.followers.previous', 180)
            ->etc());
});

test('the follower total is each account latest snapshot in range and stays null while a selected connected account has none', function () {
    $silent = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram, 'username' => 'delta']);
    $silent->forceFill(['created_at' => '2026-08-01 00:00:00'])->saveQuietly();
    $report = app(BuildWorkspaceAnalyticsReport::class);
    $selection = ['start' => '2026-09-01', 'end' => '2026-09-30'];

    expect($report->forSelection($this->workspace, $selection, [$this->instagram->id => $this->instagram->id])['summary']['followers']['value'])->toBe(100)
        ->and($report->forSelection($this->workspace, $selection, [$this->instagram->id => $this->instagram->id, $silent->id => $silent->id])['summary']['followers']['value'])->toBeNull()
        ->and($report->forSelection($this->workspace, $selection)['summary']['followers']['value'])->toBeNull();

    $silent->delete();

    expect($report->forSelection($this->workspace, $selection)['summary']['followers']['value'])->toBe(600);
});

test('untagged counts every publication without labels, including external posts, and unions with selected labels', function () {
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id]);
    ($this->publish)($this->instagram, 5, $label);
    AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'social_account_id' => $this->instagram->id,
        'social_account_key' => $this->instagram->id,
        'post_platform_id' => null,
        'platform' => Platform::Instagram,
        'provider_published_at' => CarbonImmutable::parse('2026-09-15 12:00:00', 'UTC'),
    ]);

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([], [], true))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.untagged', true)
            ->where('report.summary.posts.value', 4)
            ->where('report.summary.reactions.value', 70)
            ->etc());

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute([$this->instagram->id], [$label->id], true))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.summary.posts.value', 3)
            ->where('report.summary.reactions.value', 15)
            ->etc());

    $this->actingAs($this->user)
        ->get(analyticsChannelFilterRoute())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.filters.untagged', false)
            ->where('report.summary.posts.value', 5)
            ->etc());
});

test('account keys for every channel are resolved in two queries', function () {
    $previousKey = $this->instagram->id;
    $legacyKey = (string) Str::uuid7();
    ($this->publish)($this->facebook, 1, null, $legacyKey, '2026-09-25 12:00:00');
    AnalyticsAccountDailySnapshot::query()->where('social_account_key', $this->facebook->id)->delete();
    $accounts = $this->workspace->socialAccounts()->get();
    $resolver = app(ResolveAnalyticsAccountKey::class);

    DB::enableQueryLog();
    $keys = $resolver->forMany($accounts);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(2)
        ->and($keys[$this->instagram->id])->toBe($previousKey)
        ->and($keys[$this->facebook->id])->toBe($legacyKey)
        ->and($accounts->every(fn (SocialAccount $account): bool => $resolver->for($account) === $keys[$account->id]))->toBeTrue();
});
