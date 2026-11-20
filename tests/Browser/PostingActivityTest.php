<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\WeekStart;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function postingActivityBrowserPublication(SocialAccount $account, CarbonImmutable $publishedAt, PublicationContentType $type = PublicationContentType::Image): void
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'content_type' => $type,
        'provider_published_at' => $publishedAt,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'date' => $publishedAt->toDateString(),
    ]);
}

beforeEach(function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);

    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);
    $this->user = $user->fresh();

    $this->instagram = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $this->today = CarbonImmutable::today('UTC');

    $this->actingAs($this->user);
});

test('the workspace insights page shows the posting activity card', function () {
    foreach ([1, 2, 3] as $daysAgo) {
        postingActivityBrowserPublication($this->instagram, $this->today->subDays($daysAgo)->setTime(10, 0));
    }

    $page = visit(route('app.insights'));
    waitFor($page, '@posting-activity-current-streak');
    waitForCount($page, '@posting-activity-cell', 365);

    $page->assertSeeIn('@posting-activity-current-streak', '3')
        ->assertSeeIn('@posting-activity-best-streak', '3')
        ->assertSeeIn('@posting-activity-total', '3')
        ->assertNoJavaScriptErrors();
});

test('the channel insights page shows its posting activity and follows the post type filter', function () {
    postingActivityBrowserPublication($this->instagram, $this->today->subDays(1)->setTime(10, 0), PublicationContentType::Story);
    postingActivityBrowserPublication($this->instagram, $this->today->subDays(2)->setTime(10, 0));

    $page = visit(route('app.channels.insights', $this->instagram));
    waitForText($page, '@posting-activity-total', '2');

    $page->click('@insights-post-type-filter');
    waitFor($page, '@insights-post-type-option-instagram_story');
    $page->click('@insights-post-type-option-instagram_story');
    waitForText($page, '@posting-activity-total', '1');

    $page->assertSeeIn('@posting-activity-current-streak', '1')
        ->assertNoJavaScriptErrors();
});

test('the workspace card fills in when the first posts arrive after opening the page', function () {
    $page = visit(route('app.insights'));
    waitFor($page, '@analytics-empty-state');
    letPageRun($page, 2000);

    postingActivityBrowserPublication($this->instagram, $this->today->subDays(1)->setTime(10, 0));

    waitForText($page, '@posting-activity-total', '1');

    $page->assertNoJavaScriptErrors();
});

test('on a phone the grid opens on the latest weeks', function () {
    postingActivityBrowserPublication($this->instagram, $this->today->subDays(1)->setTime(10, 0));

    $page = visit(route('app.insights'))->on()->mobile();
    waitFor($page, '@posting-activity-cell');
    waitForState($page, '@posting-activity-scroller', 'element.scrollWidth > element.clientWidth && element.scrollLeft + element.clientWidth >= element.scrollWidth - 1');

    $page->assertNoJavaScriptErrors();
});

test('hovering a day shows its posts and the day', function () {
    postingActivityBrowserPublication($this->instagram, $this->today->subDays(1)->setTime(10, 0));
    postingActivityBrowserPublication($this->instagram, $this->today->subDays(1)->setTime(12, 0));

    $page = visit(route('app.insights'));
    waitFor($page, '@posting-activity-cell');
    $page->hover('[data-testid="posting-activity-cell"]:nth-of-type(364)');
    waitForText($page, '@posting-activity-tooltip', '2 posts');

    $page->assertSeeIn('@posting-activity-tooltip', $this->today->subDays(1)->format('j'))
        ->assertNoJavaScriptErrors();
});

test('the workspace posting activity shares as a card that turns into a post', function () {
    Storage::fake();
    postingActivityBrowserPublication($this->instagram, $this->today->subDays(1)->setTime(10, 0));

    $page = visit(route('app.insights'));
    waitFor($page, '@share-posting-activity');
    $page->click('@share-posting-activity');
    waitForState($page, '@share-chart-preview', "element.dataset.state === 'ready'");

    $page->assertAttribute('@share-chart-preview', 'data-shape', 'heatmap')
        ->click('@share-chart-post');
    waitForState($page, '@composer-base-content', "element.value === '🔥 1 day in a row posting, our longest streak yet! 1 post in the last 12 months.'");

    $page->assertNoJavaScriptErrors();
});

test('a channel under its record shares a post that names the record', function () {
    Storage::fake();

    foreach ([1, 2, 5, 6, 7] as $daysAgo) {
        postingActivityBrowserPublication($this->instagram, $this->today->subDays($daysAgo)->setTime(10, 0));
    }

    $page = visit(route('app.channels.insights', $this->instagram));
    waitFor($page, '@share-posting-activity');
    $page->click('@share-posting-activity');
    waitForState($page, '@share-chart-preview', "element.dataset.state === 'ready'");
    $page->click('@share-chart-post');
    waitForState($page, '@composer-base-content', "element.value === '🔥 2 days in a row posting. Our record is 3 days, with 5 posts in the last 12 months.'");

    $page->assertNoJavaScriptErrors();
});

test('without a streak today the post leads with the year', function () {
    Storage::fake();

    foreach ([3, 4] as $daysAgo) {
        postingActivityBrowserPublication($this->instagram, $this->today->subDays($daysAgo)->setTime(10, 0));
    }

    $page = visit(route('app.insights'));
    waitFor($page, '@share-posting-activity');
    $page->click('@share-posting-activity');
    waitForState($page, '@share-chart-preview', "element.dataset.state === 'ready'");
    $page->click('@share-chart-post');
    waitForState($page, '@composer-base-content', "element.value === '📅 2 posts in the last 12 months, and a longest streak of 2 days in a row.'");

    $page->assertNoJavaScriptErrors();
});

test('today lands on its weekday row in the week the user starts on', function (WeekStart $weekStart, int $firstWeekday) {
    $this->user->update(['week_starts_on' => $weekStart]);
    postingActivityBrowserPublication($this->instagram, $this->today->setTime(10, 0));

    $page = visit(route('app.insights'));
    waitForCount($page, '@posting-activity-cell', 365);

    $row = $page->script('(() => {
        const cells = [...document.querySelectorAll("[data-testid=posting-activity-cell]")];
        const rows = [...new Set(cells.map((cell) => Math.round(cell.getBBox().y)))].sort((a, b) => a - b);

        return rows.indexOf(Math.round(cells[cells.length - 1].getBBox().y));
    })()');

    expect($row)->toBe(($this->today->dayOfWeek - $firstWeekday + 7) % 7);
    $page->assertNoJavaScriptErrors();
})->with([
    'sunday' => [WeekStart::Sunday, 0],
    'monday' => [WeekStart::Monday, 1],
]);

test('the channel card fills in when the first posts arrive after opening the page', function () {
    $page = visit(route('app.channels.insights', $this->instagram));
    waitFor($page, '@insights-no-data');
    letPageRun($page, 2000);

    postingActivityBrowserPublication($this->instagram, $this->today->subDays(1)->setTime(10, 0));

    waitForText($page, '@posting-activity-total', '1');

    $page->assertNoJavaScriptErrors();
});
