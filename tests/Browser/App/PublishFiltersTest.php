<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

function waitForPublishFiltersInUrl(mixed $page, string $labelId, string $channelId): void
{
    waitForCondition($page, <<<JS
        ((params) => {
            const values = (name) => [...params.entries()].filter(([key]) => key.startsWith(name + '[')).map(([, value]) => value);

            return values('labels').includes('{$labelId}')
                && params.get('untagged') === '1'
                && values('channels').includes('{$channelId}')
                && params.get('tz') === 'Asia/Tokyo';
        })(new URLSearchParams(location.search))
    JS);
}

function assertPublishFilterControls(mixed $page): void
{
    waitForText($page, '@posts-label-count', '2');
    waitForText($page, '@posts-channel-count', '1');
    waitForText($page, '@publish-timezone-select', 'Tokyo');
}

beforeEach(function () {
    $this->user = User::factory()->create(['timezone' => 'UTC', 'locale' => Locale::DEFAULT]);
    $workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($this->user->account);
    $this->user = $this->user->fresh();
    connectAiTo($this->user);

    $this->linkedin = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn, 'timezone' => 'UTC']);
    SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X, 'timezone' => 'UTC']);
    $this->label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);

    $this->actingAs($this->user);
});

test('switching from the list to the calendar and the board keeps the label, untagged, channel and time zone filters', function () {
    $page = visit(route('app.posts.index', [
        'tab' => 'drafts',
        'channels' => [$this->linkedin->id],
        'tz' => 'Asia/Tokyo',
    ]))->resize(1600, 900);
    waitFor($page, '@posts-label-filter');

    $page->click('@posts-label-filter');
    waitFor($page, "@posts-label-checkbox-{$this->label->id}");
    $page->click("@posts-label-checkbox-{$this->label->id}");
    $page->click('@posts-label-untagged-checkbox');
    waitForPublishFiltersInUrl($page, $this->label->id, $this->linkedin->id);
    $page->click('@posts-label-filter');
    waitForGone($page, '@posts-label-untagged');

    $page->click('@schedule-view-calendar');
    waitForPath($page, route('app.calendar', ['view' => 'week'], absolute: false));
    waitForPublishFiltersInUrl($page, $this->label->id, $this->linkedin->id);
    assertPublishFilterControls($page);

    $page->click('@schedule-view-board');
    waitForPath($page, route('app.posts.board', absolute: false));
    waitForPublishFiltersInUrl($page, $this->label->id, $this->linkedin->id);
    assertPublishFilterControls($page);

    $page->click('@schedule-view-list');
    waitForPath($page, route('app.posts.index', absolute: false));
    waitForPublishFiltersInUrl($page, $this->label->id, $this->linkedin->id);
    assertPublishFilterControls($page);

    $page->assertNoJavaScriptErrors();
});
