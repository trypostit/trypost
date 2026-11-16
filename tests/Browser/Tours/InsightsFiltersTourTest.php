<?php

declare(strict_types=1);

use App\Enums\PostHog\TourEvent;
use App\Enums\User\Locale;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['trypost.tours.enabled' => true]);
    enablePostHog();
    Queue::fake([CaptureAccountEvent::class]);

    $this->user = User::factory()->create(['timezone' => 'UTC', 'locale' => Locale::DEFAULT]);
    $workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($this->user->account);
    $this->user = $this->user->fresh();
    connectAiTo($this->user);

    $this->actingAs($this->user);
});

/**
 * Whether the end of the filters tour reached PostHog's queue with this
 * outcome and step.
 */
function insightsTourTracked(User $user, TourEvent $event, int $step): bool
{
    return Queue::pushed(CaptureAccountEvent::class, fn (CaptureAccountEvent $job): bool => $job->event === $event
        && $job->distinctId === (string) $user->id
        && $job->properties['tour'] === 'insights.filters'
        && $job->properties['step'] === $step
        && $job->properties['steps'] === 3)->isNotEmpty();
}

test('the filters tour walks the period, channels and labels with Next and Back, and Done completes it', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);

    waitForOpen($page, '@tour-insights-filters-range-content');
    $page->assertSeeIn('@tour-insights-filters-range-content', 'Filter by period')
        ->assertSeeIn('@tour-insights-filters-range-progress', '1 of 3')
        ->assertMissing('@tour-insights-filters-range-back')
        ->assertSeeIn('@tour-insights-filters-range-next', 'Next');

    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');
    waitForGone($page, '@tour-insights-filters-range-content');
    $page->assertSeeIn('@tour-insights-filters-channels-content', 'Filter by channel')
        ->assertSeeIn('@tour-insights-filters-channels-progress', '2 of 3');

    $page->click('@tour-insights-filters-channels-back');
    waitForOpen($page, '@tour-insights-filters-range-content');
    waitForGone($page, '@tour-insights-filters-channels-content');

    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');
    $page->click('@tour-insights-filters-channels-next');
    waitForOpen($page, '@tour-insights-filters-labels-content');
    $page->assertSeeIn('@tour-insights-filters-labels-content', 'Filter by label')
        ->assertSeeIn('@tour-insights-filters-labels-progress', '3 of 3')
        ->assertSeeIn('@tour-insights-filters-labels-next', 'Done');

    $page->click('@tour-insights-filters-labels-next');
    waitForGone($page, '@tour-insights-filters-labels-content');
    waitForGone($page, '@tour-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['insights.filters' => ['status' => 'completed', 'step' => 3]]);
    waitForDatabase($page, fn (): bool => insightsTourTracked($this->user, TourEvent::Completed, 3));

    $page->navigate(route('app.insights'));
    waitFor($page, '@analytics-filters');
    $page->assertMissing('@tour-insights-filters-range-content');

    $page->assertNoJavaScriptErrors();
});

test('moving to the next step slides the one backdrop and its card to the next control together, instead of replacing them', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');
    rememberElement($page, '@tour-backdrop', 'backdrop');
    rememberElement($page, '@tour-insights-filters-range-content', 'card');

    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');
    waitForState($page, '@tour-backdrop', 'element === window.__remembered.backdrop && element.getBoundingClientRect().left > 640');
    waitForState($page, '@tour-insights-filters-channels-content', 'element === window.__remembered.card && element.getBoundingClientRect().left > 640');

    $page->assertNoJavaScriptErrors();
});

test('the arrow keys move a longer tour on and back, never past its first or last step, and leave it open for Done', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');

    $page->keys('@tour-insights-filters-range-content', 'ArrowLeft');
    letPageRun($page, 500);
    $page->assertVisible('@tour-insights-filters-range-content');

    $page->keys('@tour-insights-filters-range-content', 'ArrowRight');
    waitForOpen($page, '@tour-insights-filters-channels-content');
    waitForGone($page, '@tour-insights-filters-range-content');

    $page->keys('@tour-insights-filters-channels-content', 'ArrowLeft');
    waitForOpen($page, '@tour-insights-filters-range-content');

    $page->keys('@tour-insights-filters-range-content', 'ArrowRight');
    waitForOpen($page, '@tour-insights-filters-channels-content');
    $page->keys('@tour-insights-filters-channels-content', 'ArrowRight');
    waitForOpen($page, '@tour-insights-filters-labels-content');
    $page->keys('@tour-insights-filters-labels-content', 'ArrowRight');
    letPageRun($page, 500);

    $page->assertVisible('@tour-insights-filters-labels-content');
    expect(browserStorageItem($page, $this->user, 'tours'))->toBeNull();

    $page->click('@tour-insights-filters-labels-next');
    waitForGone($page, '@tour-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['insights.filters' => ['status' => 'completed', 'step' => 3]]);

    $page->assertNoJavaScriptErrors();
});

test('picking a period from the highlighted control moves the tour on, and the visit it makes keeps it there', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');

    $page->click('@insights-range-7d');
    waitForQueryParameter($page, 'range', '7d');
    waitForOpen($page, '@tour-insights-filters-channels-content');
    letPageRun($page, 500);

    $page->assertVisible('@tour-insights-filters-channels-content')
        ->assertMissing('@tour-insights-filters-range-content');
    expect(browserStorageItem($page, $this->user, 'tours'))->toBeNull();

    $page->assertNoJavaScriptErrors();
});

test('a dialog opened over the tour puts it away, and the tour comes back at the same step once the dialog closes', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');
    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');

    $page->keys('@tour-insights-filters-channels-content', 'ControlOrMeta+k');
    waitFor($page, '@command-palette-input');
    waitForGone($page, '@tour-backdrop');
    expect(browserStorageItem($page, $this->user, 'tours'))->toBeNull();

    $page->keys('@command-palette-input', 'Escape');
    waitForGone($page, '@command-palette-input');
    waitForOpen($page, '@tour-insights-filters-channels-content');

    $page->assertNoJavaScriptErrors();
});

test('where one user left a tour is never where it opens for the next user signed in on the same tab', function () {
    $next = User::factory()->create(['timezone' => 'UTC', 'locale' => Locale::DEFAULT]);
    $workspace = Workspace::factory()->create(['user_id' => $next->id, 'account_id' => $next->account_id]);
    $workspace->members()->attach($next->id, membershipPivot('admin'));
    $next->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($next->account);
    connectAiTo($next->fresh());

    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');
    setBrowserStorageItem($page, $this->user, 'tours', ['publish.views' => ['status' => 'completed', 'step' => 1]]);
    announceBrowserStorageFromAnotherTab($page, $this->user);
    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');

    $page->keys('@tour-insights-filters-channels-content', 'ControlOrMeta+k');
    waitFor($page, '@command-palette-item-nav-publish');
    $page->click('@command-palette-item-nav-publish');
    waitForPath($page, route('app.posts.index', absolute: false));

    $page->click('@sidebar-workspace-menu');
    waitFor($page, '@logout-button');
    $page->click('@logout-button');
    waitFor($page, '@login-email');
    $page->type('@login-email', $next->email)
        ->type('@login-password', 'password')
        ->click('@login-submit');
    waitForOpen($page, '@tour-publish-views-views-content');
    $page->click('@tour-publish-views-views-next');
    waitForGone($page, '@tour-backdrop');

    $insightsPath = route('app.insights', absolute: false);
    $page->click("@nav-{$insightsPath}");
    waitForOpen($page, '@tour-insights-filters-range-content');
    $page->assertSeeIn('@tour-insights-filters-range-progress', '1 of 3');

    $page->assertNoJavaScriptErrors();
});

test('in a right-to-left language the left arrow moves the tour on and the right arrow moves it back', function () {
    $this->user->update(['locale' => Locale::Arabic]);

    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');

    $page->keys('@tour-insights-filters-range-content', 'ArrowLeft');
    waitForOpen($page, '@tour-insights-filters-channels-content');
    waitForGone($page, '@tour-insights-filters-range-content');

    $page->keys('@tour-insights-filters-channels-content', 'ArrowRight');
    waitForOpen($page, '@tour-insights-filters-range-content');
    waitForGone($page, '@tour-insights-filters-channels-content');

    $page->assertNoJavaScriptErrors();
});

test('a press on the highlighted control that never became a click does not move the tour on later', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');

    pointerDownOn($page, '@insights-range-presets');
    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');

    clickWithoutPointer($page, '@insights-range-presets');
    letPageRun($page, 500);

    $page->assertVisible('@tour-insights-filters-channels-content')
        ->assertMissing('@tour-insights-filters-labels-content');

    $page->assertNoJavaScriptErrors();
});

test('Skip tour on a middle step records the step reached', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');

    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');
    $page->click('@tour-insights-filters-channels-skip');
    waitForGone($page, '@tour-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['insights.filters' => ['status' => 'skipped', 'step' => 2]]);
    waitForDatabase($page, fn (): bool => insightsTourTracked($this->user, TourEvent::Skipped, 2));

    $page->assertNoJavaScriptErrors();
});

test('leaving the page midway records nothing, so the tour opens again from its first step', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');
    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');

    $page->navigate(route('app.insights'));
    waitForOpen($page, '@tour-insights-filters-range-content');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBeNull();

    $page->assertNoJavaScriptErrors();
});

test('narrowing the window to a phone hides the tour, and widening it opens the tour at the same step', function () {
    $page = visit(route('app.insights'))->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-range-content');
    $page->click('@tour-insights-filters-range-next');
    waitForOpen($page, '@tour-insights-filters-channels-content');

    $page->resize(390, 844);
    waitForGone($page, '@tour-backdrop');
    $page->assertMissing('@tour-insights-filters-channels-content');
    expect(browserStorageItem($page, $this->user, 'tours'))->toBeNull();

    $page->resize(1280, 900);
    waitForOpen($page, '@tour-insights-filters-channels-content');

    $page->assertNoJavaScriptErrors();
});

test('on a phone no tour opens', function () {
    $page = visit(route('app.insights'))->resize(390, 844);
    waitFor($page, '@analytics-filters');

    $page->assertMissing('@tour-insights-filters-range-content');

    $page->assertNoJavaScriptErrors();
});
