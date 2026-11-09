<?php

declare(strict_types=1);

use App\Enums\Changelog\Reaction;
use App\Enums\Changelog\Tag;
use App\Enums\User\Locale;
use App\Enums\User\ReferralSource;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use App\Models\Workspace;

function waitForChangelogViews(mixed $page, User $user, int $count): void
{
    waitForDatabase($page, fn () => ChangelogEntryView::where('user_id', $user->id)->count() >= $count);
}

function waitForChangelogReaction(mixed $page, ChangelogEntry $entry, ?Reaction $reaction): void
{
    waitForDatabase($page, fn () => ChangelogEntryView::where('changelog_entry_id', $entry->id)->value('reaction') === $reaction?->value);
}

function createChangelogUser(array $attributes = []): User
{
    $user = User::factory()->create(['referral_source' => ReferralSource::Google->value, ...$attributes]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user;
}

/**
 * The Help item is a Radix sub-trigger, which opens on a pointer move before the click.
 */
function openChangelogHelpMenu(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            const find = async (testId) => {
                for (let attempt = 0; attempt < 100; attempt++) {
                    const element = document.querySelector(`[data-testid="${testId}"]`);
                    if (element) return element;
                    await new Promise((resolve) => setTimeout(resolve, 50));
                }
            };
            (await find('sidebar-workspace-menu')).click();
            const trigger = await find('sidebar-support-trigger');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
            await find('sidebar-support-changelog');
        })();
    JS);
}

test('opening the sidebar card shows the newest entry, records the view, hides the card and takes a reaction', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser([
        'created_at' => now()->subDays(30),
    ]);

    $newest = ChangelogEntry::factory()->create([
        'slug' => '2026-10-14-newest',
        'title' => 'Newest feature',
        'body' => 'The **newest** feature body.',
        'tags' => [Tag::New, Tag::Beta],
        'published_on' => today(),
    ]);
    $older = ChangelogEntry::factory()->create([
        'slug' => '2026-10-13-older',
        'title' => 'Older feature',
        'published_on' => today()->subDay(),
    ]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForText($page, '@changelog-card-title', 'Newest feature');

    expect($page->script("document.querySelector('[data-testid=\"changelog-card\"] [data-testid^=\"changelog-tag-\"]') === null"))->toBeTrue();

    $page->click('@changelog-card');
    waitForText($page, '@changelog-entry-title', 'Newest feature');
    $page->assertSeeIn('@changelog-entry', 'The newest feature body.')
        ->assertSeeIn('@changelog-entry-tags', 'New')
        ->assertSeeIn('@changelog-entry-tags', 'Beta')
        ->assertSeeIn('@changelog-back', 'All updates');
    expect($page->script("document.querySelector('[data-testid=\"changelog-dialog\"]').getAttribute('aria-labelledby') && document.getElementById(document.querySelector('[data-testid=\"changelog-dialog\"]').getAttribute('aria-labelledby')).textContent.trim()"))
        ->toBe('Newest feature');
    waitForGone($page, '@changelog-card');

    expect(ChangelogEntryView::where('user_id', $user->id)->pluck('changelog_entry_id')->all())
        ->toBe([$newest->id]);

    $page->click('@changelog-back');
    waitForState($page, '@changelog-item-2026-10-13-older', 'true');
    $page->assertPresent('@changelog-unseen-2026-10-13-older')
        ->assertMissing('@changelog-unseen-2026-10-14-newest');

    $page->click('@changelog-item-2026-10-13-older');
    waitForText($page, '@changelog-entry-title', 'Older feature');
    waitForChangelogViews($page, $user, 2);

    expect(ChangelogEntryView::where('user_id', $user->id)->pluck('changelog_entry_id')->sort()->values()->all())
        ->toEqual(collect([$newest->id, $older->id])->sort()->values()->all());

    $page->assertSeeIn('@changelog-reaction', 'Do you like it?')
        ->click('@changelog-reaction-like');
    waitForChangelogReaction($page, $older, Reaction::Like);
    expect(ChangelogEntryView::where('changelog_entry_id', $older->id)->sole()->reaction)->toBe(Reaction::Like)
        ->and($page->script("document.querySelector('[data-testid=\"changelog-reaction-like\"]').getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"changelog-reaction-dislike\"] span')).filter"))->toContain('grayscale')
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"changelog-reaction-like\"] span')).filter"))->toBe('none');

    $page->click('@changelog-reaction-dislike');
    waitForChangelogReaction($page, $older, Reaction::Dislike);
    expect(ChangelogEntryView::where('changelog_entry_id', $older->id)->sole()->reaction)->toBe(Reaction::Dislike);

    $page->click('@changelog-reaction-dislike');
    waitForChangelogReaction($page, $older, null);
    expect(ChangelogEntryView::where('changelog_entry_id', $older->id)->sole()->reaction)->toBeNull();

    $page->click('@changelog-close');
    waitForGone($page, '@changelog-dialog');

    $page->assertMissing('@changelog-card')
        ->assertMissing('@changelog-dialog')
        ->assertNoJavaScriptErrors();
});

test('the help menu item is translated and opens the list', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser([
        'locale' => Locale::PortugueseBrazil,
    ]);

    ChangelogEntry::factory()->create(['slug' => '2026-10-13-listed', 'published_on' => today()->subYear()]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');

    openChangelogHelpMenu($page);

    $page->assertSeeIn('@sidebar-support-changelog', __('sidebar.support.changelog', [], 'pt-BR'));
    expect(__('sidebar.support.changelog', [], 'pt-BR'))->toBe('Novidades');

    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-2026-10-13-listed', 'true');

    $page->assertPresent('@changelog-unseen-2026-10-13-listed')
        ->assertMissing('@changelog-card')
        ->assertNoJavaScriptErrors();
});

test('hovering a list item highlights it, only hovering its cover zooms the cover, and the whole item opens the entry', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser();

    ChangelogEntry::factory()->create([
        'slug' => '2026-10-13-hovered',
        'title' => 'Hovered feature',
        'published_on' => today()->subYear(),
    ]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');

    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-cover-2026-10-13-hovered', 'true');

    $coverScale = "getComputedStyle(document.querySelector('[data-testid=\"changelog-item-zoom-2026-10-13-hovered\"]')).scale";
    $itemBackground = "getComputedStyle(document.querySelector('[data-testid=\"changelog-item-2026-10-13-hovered\"]')).backgroundColor";
    $idleBackground = $page->script($itemBackground);

    $page->hover('@changelog-item-title-2026-10-13-hovered');
    waitForState($page, '@changelog-item-2026-10-13-hovered', "getComputedStyle(element).backgroundColor !== '{$idleBackground}'");
    expect($page->script($itemBackground))->not->toBe($idleBackground)
        ->and($page->script($coverScale))->toBe('none');

    $page->hover('@changelog-item-cover-2026-10-13-hovered');
    waitForState($page, '@changelog-item-zoom-2026-10-13-hovered', "getComputedStyle(element).scale === '1.04'");
    expect($page->script($coverScale))->toBe('1.04');

    $page->click('@changelog-item-title-2026-10-13-hovered');
    waitForText($page, '@changelog-entry-title', 'Hovered feature');

    $page->assertSeeIn('@changelog-entry-title', 'Hovered feature')
        ->assertNoJavaScriptErrors();
});

test('the list loads the next page on scroll, reopens on the list and shows an empty state when there is nothing', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser();
    collect(range(1, 7))->each(fn (int $daysAgo) => ChangelogEntry::factory()->create([
        'slug' => "2026-09-0{$daysAgo}-paged",
        'published_on' => today()->subDays($daysAgo),
    ]));

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');
    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-2026-09-01-paged', 'true');

    expect($page->script("document.querySelectorAll('[data-testid^=\"changelog-item-cover-\"]').length"))->toBe(5);

    $page->script("document.querySelector('[data-testid=\"changelog-dialog\"]').scrollTop = 100000");
    waitForState($page, '@changelog-item-2026-09-07-paged', 'true');

    expect($page->script("document.querySelectorAll('[data-testid^=\"changelog-item-cover-\"]').length"))->toBe(7);

    $page->click('@changelog-item-title-2026-09-07-paged');
    waitForState($page, '@changelog-entry', 'true');
    waitForState($page, '@changelog-dialog', 'element.scrollTop === 0');

    expect($page->script("document.querySelector('[data-testid=\"changelog-dialog\"]').scrollTop"))->toBe(0);
    $page->click('@changelog-close');
    waitForGone($page, '@changelog-dialog');

    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-2026-09-01-paged', 'true');
    waitForCount($page, '[data-testid^="changelog-item-cover-"]', 5);

    expect($page->script("document.querySelectorAll('[data-testid^=\"changelog-item-cover-\"]').length"))->toBe(5);
    $page->assertMissing('@changelog-entry')
        ->assertNoJavaScriptErrors();

    ChangelogEntry::query()->delete();

    $empty = visit(route('app.calendar'))->resize(1280, 900);
    waitForState($empty, '@sidebar-workspace-menu', 'true');
    openChangelogHelpMenu($empty);
    $empty->click('@sidebar-support-changelog');
    waitForState($empty, '@changelog-empty', 'true');

    $empty->assertSeeIn('@changelog-empty', 'Nothing new yet')
        ->assertNoJavaScriptErrors();
});

test('a reaction that fails to save is undone and explained', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser();
    $entry = ChangelogEntry::factory()->create(['slug' => '2026-10-13-failing', 'published_on' => today()->subYear()]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');
    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-title-2026-10-13-failing', 'true');
    $page->click('@changelog-item-title-2026-10-13-failing');
    waitForState($page, '@changelog-reaction-like', 'true');
    waitForChangelogViews($page, $user, 1);

    $entry->delete();
    $page->click('@changelog-reaction-like');
    waitForCondition($page, "document.body.innerText.includes(\"Couldn't save your answer\")");

    expect($page->script("document.querySelector('[data-testid=\"changelog-reaction-like\"]').getAttribute('aria-pressed')"))->toBe('false');
    $page->assertSee("Couldn't save your answer. Try again.");
});

test('nothing of the changelog shows when it is turned off', function () {
    config(['trypost.changelog.enabled' => false]);

    $user = createChangelogUser(['created_at' => now()->subDays(30)]);
    ChangelogEntry::factory()->create(['published_on' => today()]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');
    $page->script(<<<'JS'
        (async () => {
            const find = async (testId) => {
                for (let attempt = 0; attempt < 100; attempt++) {
                    const element = document.querySelector(`[data-testid="${testId}"]`);
                    if (element) return element;
                    await new Promise((resolve) => setTimeout(resolve, 50));
                }
            };
            (await find('sidebar-workspace-menu')).click();
            const trigger = await find('sidebar-support-trigger');
            trigger.dispatchEvent(new PointerEvent('pointermove', { bubbles: true }));
            trigger.click();
            await find('sidebar-support-community-title');
        })();
    JS);

    $page->assertPresent('@sidebar-support-community-title')
        ->assertMissing('@sidebar-support-changelog')
        ->assertMissing('@changelog-card')
        ->assertNoJavaScriptErrors();
});

test('a second reaction click while the first is saving is ignored', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser();
    $entry = ChangelogEntry::factory()->create(['slug' => '2026-10-13-double', 'published_on' => today()->subYear()]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');
    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-title-2026-10-13-double', 'true');
    $page->click('@changelog-item-title-2026-10-13-double');
    waitForState($page, '@changelog-reaction-like', 'true');
    waitForChangelogViews($page, $user, 1);

    $page->script("document.querySelector('[data-testid=\"changelog-reaction-like\"]').click(); document.querySelector('[data-testid=\"changelog-reaction-dislike\"]').click();");
    waitForChangelogReaction($page, $entry, Reaction::Like);
    $page->script('new Promise((resolve) => setTimeout(resolve, 500))');

    expect(ChangelogEntryView::where('changelog_entry_id', $entry->id)->sole()->reaction)->toBe(Reaction::Like)
        ->and($page->script("document.querySelector('[data-testid=\"changelog-reaction-like\"]').getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script("document.querySelector('[data-testid=\"changelog-reaction-dislike\"]').getAttribute('aria-pressed')"))->toBe('false');
});

test('a browser Back closes the modal, and reopening shows a fresh list', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser();
    ChangelogEntry::factory()->create(['slug' => '2026-10-13-fresh', 'published_on' => today()->subYear()]);
    $calendarPath = route('app.calendar', absolute: false);
    $insightsPath = route('app.insights', absolute: false);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->navigate(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');
    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-unseen-2026-10-13-fresh', 'true');
    $page->click('@changelog-item-title-2026-10-13-fresh');
    waitForChangelogViews($page, $user, 1);
    $page->click('@changelog-close');
    waitForGone($page, '@changelog-dialog');

    $page->script("document.querySelector('[data-testid=\"nav-{$insightsPath}\"]').click()");
    waitForPath($page, $insightsPath);
    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-2026-10-13-fresh', 'true');

    $page->back();
    waitForPath($page, $calendarPath);
    waitForGone($page, '@changelog-dialog');

    $page->assertPathIs($calendarPath)->assertMissing('@changelog-dialog');

    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-2026-10-13-fresh', 'true');

    $page->assertMissing('@changelog-unseen-2026-10-13-fresh')
        ->assertNoJavaScriptErrors();

    expect(ChangelogEntryView::where('user_id', $user->id)->count())->toBe(1);
});

test('a browser Back between two entries of the same page closes the modal too', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser();
    ChangelogEntry::factory()->create(['slug' => '2026-10-13-same', 'published_on' => today()->subYear()]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->navigate(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');
    $page->script('window.history.pushState(window.history.state, "", location.href)');

    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-item-2026-10-13-same', 'true');

    $page->back();
    waitForGone($page, '@changelog-dialog');

    $page->assertPathIs(route('app.calendar', absolute: false))
        ->assertMissing('@changelog-dialog')
        ->assertNoJavaScriptErrors();
});

test('an open that fails to record keeps the entry marked as unseen', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = createChangelogUser();
    $entry = ChangelogEntry::factory()->create(['slug' => '2026-10-13-unrecorded', 'published_on' => today()->subYear()]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForState($page, '@sidebar-workspace-menu', 'true');
    openChangelogHelpMenu($page);
    $page->click('@sidebar-support-changelog');
    waitForState($page, '@changelog-unseen-2026-10-13-unrecorded', 'true');

    $entry->update(['published_on' => today()->addDay()]);
    $page->click('@changelog-item-title-2026-10-13-unrecorded');
    waitForState($page, '@changelog-entry', 'true');
    $page->click('@changelog-back');
    waitForState($page, '@changelog-unseen-2026-10-13-unrecorded', 'true');

    $page->assertPresent('@changelog-unseen-2026-10-13-unrecorded');
    expect(ChangelogEntryView::count())->toBe(0);
});
