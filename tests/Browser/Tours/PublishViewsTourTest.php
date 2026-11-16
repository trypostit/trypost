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

test('the views tour opens over a dark page once: "Got it" completes it on every page that has the view switch', function () {
    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');
    waitFor($page, '@tour-publish-views-views-backdrop');

    $layers = $page->script(<<<'JS'
        (() => {
            const zIndex = (element) => Number(getComputedStyle(element).zIndex);
            const content = document.querySelector('[data-testid="tour-publish-views-views-content"]');

            return {
                card: zIndex(content.closest('[data-reka-popper-content-wrapper]')),
                backdrop: zIndex(document.querySelector('[data-testid="tour-publish-views-views-backdrop"]')),
            };
        })()
    JS);
    expect($layers['card'])->toBeGreaterThan($layers['backdrop']);

    $page->assertSeeIn('@tour-publish-views-views-content', 'Pick how you see your posts');
    $page->assertMissing('@tour-publish-views-views-tag');
    $page->assertMissing('@tour-publish-views-views-progress');

    $page->click('@tour-publish-views-views-next');
    waitForGone($page, '@tour-publish-views-views-content');
    waitForGone($page, '@tour-publish-views-views-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['publish.views' => ['status' => 'completed', 'step' => 1]]);

    $page->click('@schedule-view-calendar');
    waitForPath($page, route('app.calendar', ['view' => 'week'], absolute: false));
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});

test('closing the tour with Escape skips it for good', function () {
    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');

    $page->keys('@tour-publish-views-views-content', 'Escape');
    waitForGone($page, '@tour-publish-views-views-backdrop');

    expect(browserStorageItem($page, $this->user, 'tours'))->toBe(['publish.views' => ['status' => 'skipped', 'step' => 1]]);

    $page->navigate(route('app.posts.index'));
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});

test('tours stay closed when they are turned off', function () {
    config(['trypost.tours.enabled' => false]);

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitFor($page, '@schedule-view-board');
    $page->assertMissing('@tour-publish-views-views-content');

    $page->assertNoJavaScriptErrors();
});
