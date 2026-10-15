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

function waitForChangelogCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForChangelogTestId(mixed $page, string $testId): void
{
    waitForChangelogCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')");
}

function waitForChangelogText(mixed $page, string $testId, string $text): void
{
    waitForChangelogCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.textContent.includes('{$text}')");
}

function waitForChangelogTestIdGone(mixed $page, string $testId): void
{
    waitForChangelogCondition($page, "!document.querySelector('[data-testid=\"{$testId}\"]')");
}

function waitForChangelogViews(mixed $page, User $user, int $count): void
{
    for ($attempt = 0; $attempt < 100 && ChangelogEntryView::where('user_id', $user->id)->count() < $count; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 50))');
    }
}

function waitForChangelogReaction(mixed $page, ChangelogEntry $entry, ?Reaction $reaction): void
{
    for ($attempt = 0; $attempt < 100 && ChangelogEntryView::where('changelog_entry_id', $entry->id)->value('reaction') !== $reaction?->value; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 50))');
    }
}

test('opening the sidebar card shows the newest entry, records the view, hides the card and takes a reaction', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = User::factory()->create([
        'referral_source' => ReferralSource::Google->value,
        'created_at' => now()->subDays(30),
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

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
    waitForChangelogText($page, 'changelog-card-title', 'Newest feature');

    expect($page->script("document.querySelector('[data-testid=\"changelog-card\"] [data-testid^=\"changelog-tag-\"]') === null"))->toBeTrue();

    $page->click('@changelog-card');
    waitForChangelogText($page, 'changelog-entry-title', 'Newest feature');
    $page->assertSeeIn('@changelog-entry', 'The newest feature body.')
        ->assertSeeIn('@changelog-entry-tags', 'New')
        ->assertSeeIn('@changelog-entry-tags', 'Beta');
    waitForChangelogTestIdGone($page, 'changelog-card');

    expect(ChangelogEntryView::where('user_id', $user->id)->pluck('changelog_entry_id')->all())
        ->toBe([$newest->id]);

    $page->click('@changelog-back');
    waitForChangelogTestId($page, 'changelog-item-2026-10-13-older');
    $page->assertPresent('@changelog-unseen-2026-10-13-older')
        ->assertMissing('@changelog-unseen-2026-10-14-newest');

    $page->click('@changelog-item-2026-10-13-older');
    waitForChangelogText($page, 'changelog-entry-title', 'Older feature');
    waitForChangelogViews($page, $user, 2);

    expect(ChangelogEntryView::where('user_id', $user->id)->pluck('changelog_entry_id')->sort()->values()->all())
        ->toEqual(collect([$newest->id, $older->id])->sort()->values()->all());

    $page->assertSeeIn('@changelog-reaction', 'Do you like it?')
        ->click('@changelog-reaction-like');
    waitForChangelogReaction($page, $older, Reaction::Like);
    expect(ChangelogEntryView::where('changelog_entry_id', $older->id)->sole()->reaction)->toBe(Reaction::Like)
        ->and($page->script("document.querySelector('[data-testid=\"changelog-reaction-like\"]').getAttribute('aria-pressed')"))->toBe('true');

    $page->click('@changelog-reaction-dislike');
    waitForChangelogReaction($page, $older, Reaction::Dislike);
    expect(ChangelogEntryView::where('changelog_entry_id', $older->id)->sole()->reaction)->toBe(Reaction::Dislike);

    $page->click('@changelog-reaction-dislike');
    waitForChangelogReaction($page, $older, null);
    expect(ChangelogEntryView::where('changelog_entry_id', $older->id)->sole()->reaction)->toBeNull();

    $page->click('@changelog-close');
    waitForChangelogTestIdGone($page, 'changelog-dialog');

    $page->assertMissing('@changelog-card')
        ->assertMissing('@changelog-dialog')
        ->assertNoJavaScriptErrors();
});

test('the help menu item is translated and opens the list', function () {
    config(['trypost.changelog.enabled' => true]);

    $user = User::factory()->create([
        'locale' => Locale::PortugueseBrazil,
        'referral_source' => ReferralSource::Google->value,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    ChangelogEntry::factory()->create(['slug' => '2026-10-13-listed', 'published_on' => today()->subYear()]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitForChangelogTestId($page, 'sidebar-workspace-menu');

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

    $page->assertSeeIn('@sidebar-support-changelog', __('sidebar.support.changelog', [], 'pt-BR'));
    expect(__('sidebar.support.changelog', [], 'pt-BR'))->toBe('Novidades');

    $page->click('@sidebar-support-changelog');
    waitForChangelogTestId($page, 'changelog-item-2026-10-13-listed');

    $page->assertPresent('@changelog-unseen-2026-10-13-listed')
        ->assertMissing('@changelog-card')
        ->assertNoJavaScriptErrors();
});
