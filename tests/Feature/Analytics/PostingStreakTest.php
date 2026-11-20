<?php

declare(strict_types=1);

use App\Actions\Analytics\BuildPostingStreak;
use App\Actions\Analytics\RankPostingStreaks;
use App\Actions\Analytics\ShowPostingStreak;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\WeekStart;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Bus::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $wednesday = now('UTC')->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->addDays(2)->setTime(12, 0);
    $this->travelTo($wednesday);

    $this->workspace = Workspace::factory()->create();
    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::Instagram]);
    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'platform' => Platform::LinkedIn]);
    $this->thisWeek = CarbonImmutable::now('UTC')->startOfWeek(CarbonImmutable::MONDAY);
});

function postingStreakPublication(SocialAccount $account, CarbonImmutable $at, ?Post $post = null): AnalyticsPublication
{
    return AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'platform' => $account->platform,
        'post_id' => $post?->id,
        'provider_published_at' => $at,
    ]);
}

function postingStreakPost(SocialAccount $account, CarbonImmutable $at, PublishStatus $status = PublishStatus::Published): Post
{
    $post = Post::factory()->forAccount($account)->published()->create();
    $post->forceFill(['publish_status' => $status, 'published_at' => $at])->save();

    return $post;
}

/**
 * @return array{weeks: int, posted_this_week: bool}
 */
function postingStreak(Workspace $workspace, string $timezone = 'UTC', WeekStart $weekStart = WeekStart::Monday): array
{
    return app(BuildPostingStreak::class)->execute($workspace, $timezone, $weekStart);
}

test('it counts the weeks in a row with a post, this week included', function () {
    foreach ([0, 1, 2] as $weeksAgo) {
        postingStreakPublication($this->instagram, $this->thisWeek->subWeeks($weeksAgo)->addDay());
    }

    expect(postingStreak($this->workspace))->toBe(['weeks' => 3, 'posted_this_week' => true]);
});

test('a week without a post yet keeps the streak of the weeks before it', function () {
    foreach ([1, 2] as $weeksAgo) {
        postingStreakPublication($this->instagram, $this->thisWeek->subWeeks($weeksAgo)->addDay());
    }

    expect(postingStreak($this->workspace))->toBe(['weeks' => 2, 'posted_this_week' => false]);
});

test('a week without posts before last week ends the streak', function () {
    postingStreakPublication($this->instagram, $this->thisWeek->subWeeks(2)->addDay());

    expect(postingStreak($this->workspace))->toBe(['weeks' => 0, 'posted_this_week' => false]);
});

test('posts from networks outside insights count through trypost', function () {
    postingStreakPost($this->linkedin, $this->thisWeek->addDay());
    postingStreakPublication($this->instagram, $this->thisWeek->subWeek()->addDay());

    expect(postingStreak($this->workspace)['weeks'])->toBe(2);
});

test('a linkedin post that did not go out does not count', function () {
    postingStreakPost($this->linkedin, $this->thisWeek->addDay(), PublishStatus::Failed);

    expect(postingStreak($this->workspace)['weeks'])->toBe(0);
});

test('weeks follow the user week start and zone', function () {
    $sunday = $this->thisWeek->subDay()->setTime(12, 0);
    postingStreakPublication($this->instagram, $sunday);

    expect(postingStreak($this->workspace, 'UTC', WeekStart::Monday))->toBe(['weeks' => 1, 'posted_this_week' => false])
        ->and(postingStreak($this->workspace, 'UTC', WeekStart::Sunday))->toBe(['weeks' => 1, 'posted_this_week' => true]);
});

test('another workspace does not count', function () {
    $other = SocialAccount::factory()->create(['platform' => Platform::Instagram]);
    postingStreakPublication($other, $this->thisWeek->addDay());

    expect(postingStreak($this->workspace)['weeks'])->toBe(0);
});

test('the ranking places a streak among the workspaces with app access', function () {
    foreach (range(0, 3) as $weeksAgo) {
        postingStreakPublication($this->instagram, $this->thisWeek->subWeeks($weeksAgo)->addDay());
    }

    foreach (range(1, 9) as $_) {
        $workspace = Workspace::factory()->create();
        subscribeAccount($workspace->account);
        $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
        postingStreakPublication($account, $this->thisWeek->addDay());
    }

    subscribeAccount($this->workspace->account);
    app(RankPostingStreaks::class)->execute();

    expect(RankPostingStreaks::topPercent(4))->toBe(10)
        ->and(RankPostingStreaks::topPercent(1))->toBeNull();
});

test('the sidebar gets nothing without a streak and the streak with its rank otherwise', function () {
    $user = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);

    expect(ShowPostingStreak::cached($user, $this->workspace))->toBeNull();

    Cache::flush();
    postingStreakPublication($this->instagram, $this->thisWeek->addDay());

    expect(ShowPostingStreak::cached($user, $this->workspace))->toBe(['weeks' => 1, 'posted_this_week' => true, 'top_percent' => null]);
});

test('every app page shares the streak of the current workspace', function () {
    $user = User::factory()->create(['timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);
    $this->workspace->update(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $this->workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($user->account);
    postingStreakPublication($this->instagram, $this->thisWeek->addDay());

    $this->actingAs($user)
        ->get(route('app.insights'))
        ->assertInertia(fn ($page) => $page->where('postingStreak.weeks', 1)->etc());
});

test('the sidebar streak names its rank once the workspaces are ranked', function () {
    $user = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);
    subscribeAccount($this->workspace->account);
    postingStreakPublication($this->instagram, $this->thisWeek->addDay());

    foreach (range(1, 9) as $_) {
        subscribeAccount(Workspace::factory()->create()->account);
    }

    app(RankPostingStreaks::class)->execute();

    expect(ShowPostingStreak::cached($user, $this->workspace))->toBe(['weeks' => 1, 'posted_this_week' => true, 'top_percent' => 10]);
});

test('a member sees the workspace streak once the account has app access', function () {
    config(['trypost.self_hosted' => false]);
    $owner = User::factory()->create();
    $this->workspace->update(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    subscribeAccount($owner->account);
    $member = workspaceMember($this->workspace->fresh(), 'member', ['timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);
    postingStreakPublication($this->instagram, $this->thisWeek->addDay());

    $this->actingAs($member)
        ->get(route('app.insights'))
        ->assertInertia(fn ($page) => $page->where('postingStreak.weeks', 1)->etc());
});

test('the ranking leaves out workspaces without app access', function () {
    config(['trypost.self_hosted' => false]);
    subscribeAccount($this->workspace->account);
    postingStreakPublication($this->instagram, $this->thisWeek->addDay());

    foreach (range(1, 9) as $_) {
        subscribeAccount(Workspace::factory()->create()->account);
    }

    $unsubscribed = SocialAccount::factory()->create(['platform' => Platform::Instagram]);

    foreach (range(0, 5) as $weeksAgo) {
        postingStreakPublication($unsubscribed, $this->thisWeek->subWeeks($weeksAgo)->addDay());
    }

    app(RankPostingStreaks::class)->execute();

    expect(RankPostingStreaks::topPercent(1))->toBe(10);
});

test('workspaces tied at the top share the same rank', function () {
    foreach (range(1, 2) as $_) {
        $workspace = Workspace::factory()->create();
        subscribeAccount($workspace->account);
        $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);

        foreach (range(0, 2) as $weeksAgo) {
            postingStreakPublication($account, $this->thisWeek->subWeeks($weeksAgo)->addDay());
        }
    }

    foreach (range(1, 8) as $_) {
        subscribeAccount(Workspace::factory()->create()->account);
    }

    app(RankPostingStreaks::class)->execute();

    expect(RankPostingStreaks::topPercent(3))->toBe(25);
});

test('a streak longer than the window reads as the window', function () {
    foreach (range(0, BuildPostingStreak::WEEKS + 5) as $weeksAgo) {
        postingStreakPublication($this->instagram, $this->thisWeek->subWeeks($weeksAgo)->addDay());
    }

    expect(postingStreak($this->workspace)['weeks'])->toBe(BuildPostingStreak::WEEKS);
});

test('the ranking runs every day', function () {
    $ranking = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'analytics:rank-posting-streaks'));

    expect($ranking?->expression)->toBe('0 0 * * *');
});

test('the sidebar reads the streak from the cache on the next page, with or without a streak', function (bool $posted) {
    $user = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);

    if ($posted) {
        postingStreakPublication($this->instagram, $this->thisWeek->addDay());
    }

    ShowPostingStreak::cached($user, $this->workspace);
    DB::enableQueryLog();
    ShowPostingStreak::cached($user, $this->workspace);

    expect(DB::getQueryLog())->toBeEmpty();
})->with(['without a streak' => false, 'with a streak' => true]);

test('the sidebar streak counts a post as soon as it goes out on a network outside insights', function () {
    $user = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);
    $post = Post::factory()->forAccount($this->linkedin)->create();

    expect(ShowPostingStreak::cached($user, $this->workspace))->toBeNull();

    $post->forceFill(['publish_status' => PublishStatus::Published, 'published_at' => $this->thisWeek->addDay()])->save();

    expect(data_get(ShowPostingStreak::cached($user, $this->workspace), 'weeks'))->toBe(1);
});

test('the sidebar streak counts a publication as soon as insights finds it', function () {
    $user = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);

    expect(ShowPostingStreak::cached($user, $this->workspace))->toBeNull();

    postingStreakPublication($this->instagram, $this->thisWeek->addDay());

    expect(data_get(ShowPostingStreak::cached($user, $this->workspace), 'weeks'))->toBe(1);
});

test('a post going out in another workspace leaves this streak cached', function () {
    $user = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);
    ShowPostingStreak::cached($user, $this->workspace);

    postingStreakPublication(SocialAccount::factory()->create(['platform' => Platform::Instagram]), $this->thisWeek->addDay());
    DB::enableQueryLog();
    ShowPostingStreak::cached($user, $this->workspace);

    expect(DB::getQueryLog())->toBeEmpty();
});

test('a handful of workspaces ranks nothing, so a small install never ranks itself', function () {
    subscribeAccount($this->workspace->account);
    postingStreakPublication($this->instagram, $this->thisWeek->addDay());

    foreach (range(1, RankPostingStreaks::MIN_RANKED - 2) as $_) {
        subscribeAccount(Workspace::factory()->create()->account);
    }

    app(RankPostingStreaks::class)->execute();

    expect(RankPostingStreaks::topPercent(1))->toBeNull()
        ->and(RankPostingStreaks::topPercent(50))->toBeNull();
});

test('a post moving between steps that do not count keeps the streak cached', function () {
    $user = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);
    $post = Post::factory()->forAccount($this->linkedin)->create();
    ShowPostingStreak::cached($user, $this->workspace);

    $post->forceFill(['publish_status' => PublishStatus::Publishing])->save();
    DB::enableQueryLog();
    ShowPostingStreak::cached($user, $this->workspace);

    expect(DB::getQueryLog())->toBeEmpty();
});

test('the sidebar streak follows a publication insights re-dates or drops', function () {
    $user = User::factory()->create(['current_workspace_id' => $this->workspace->id, 'timezone' => 'UTC', 'week_starts_on' => WeekStart::Monday]);
    $publication = postingStreakPublication($this->instagram, $this->thisWeek->addDay());

    expect(data_get(ShowPostingStreak::cached($user, $this->workspace), 'weeks'))->toBe(1);

    $publication->update(['provider_published_at' => $this->thisWeek->subWeeks(3)]);

    expect(ShowPostingStreak::cached($user, $this->workspace))->toBeNull();

    $publication->update(['provider_published_at' => $this->thisWeek->addDay()]);
    ShowPostingStreak::cached($user, $this->workspace);
    $publication->delete();

    expect(ShowPostingStreak::cached($user, $this->workspace))->toBeNull();
});
