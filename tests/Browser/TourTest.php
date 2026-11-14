<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    config(['trypost.tours.enabled' => true]);

    $this->user = User::factory()->create(['timezone' => 'UTC', 'locale' => Locale::DEFAULT]);
    $workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($this->user->account);
    $this->user = $this->user->fresh();
    connectAiTo($this->user);

    $this->actingAs($this->user);
});

test('the board tour opens over a dark page once: "Got it" completes it on every page that has the view switch', function () {
    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-board-board-content');
    waitFor($page, '@tour-publish-board-board-backdrop');

    $layers = $page->script(<<<'JS'
        (() => {
            const zIndex = (element) => Number(getComputedStyle(element).zIndex);
            const content = document.querySelector('[data-testid="tour-publish-board-board-content"]');

            return {
                card: zIndex(content.closest('[data-reka-popper-content-wrapper]')),
                backdrop: zIndex(document.querySelector('[data-testid="tour-publish-board-board-backdrop"]')),
            };
        })()
    JS);
    expect($layers['card'])->toBeGreaterThan($layers['backdrop']);

    $page->assertSeeIn('@tour-publish-board-board-tag', 'New');
    waitForState($page, '@tour-publish-board-board-tag', "getComputedStyle(element).backgroundColor === 'rgb(124, 58, 237)' && getComputedStyle(element).color === 'rgb(255, 255, 255)'");
    $page->assertSeeIn('@tour-publish-board-board-content', 'Board view');
    $page->assertMissing('@tour-publish-board-board-progress');

    $page->click('@tour-publish-board-board-next');
    waitForGone($page, '@tour-publish-board-board-content');
    waitForGone($page, '@tour-publish-board-board-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['publish.board' => ['status' => 'completed', 'step' => 1]]);

    $page->click('@schedule-view-calendar');
    waitForPath($page, route('app.calendar', ['view' => 'week'], absolute: false));
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-board-board-content');

    $page->assertNoJavaScriptErrors();
});

test('closing the tour with Escape skips it for good', function () {
    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-board-board-content');

    $page->keys('@tour-publish-board-board-content', 'Escape');
    waitForGone($page, '@tour-publish-board-board-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['publish.board' => ['status' => 'skipped', 'step' => 1]]);

    $page->navigate(route('app.posts.index'));
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-board-board-content');

    $page->assertNoJavaScriptErrors();
});

test('tours stay closed when they are turned off', function () {
    config(['trypost.tours.enabled' => false]);

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-board-board-content');

    $page->assertNoJavaScriptErrors();
});
