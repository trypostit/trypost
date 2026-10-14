<?php

declare(strict_types=1);

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

test('opening the sidebar card shows the entry, records the view and moves the card on', function () {
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

    $page->click('@changelog-card');
    waitForChangelogText($page, 'changelog-entry-title', 'Newest feature');
    $page->assertSeeIn('@changelog-entry', 'The newest feature body.');
    waitForChangelogText($page, 'changelog-card-title', 'Older feature');

    expect(ChangelogEntryView::where('user_id', $user->id)->pluck('changelog_entry_id')->all())
        ->toBe([$newest->id]);

    $page->click('@changelog-back');
    waitForChangelogTestId($page, 'changelog-item-2026-10-13-older');
    $page->assertPresent('@changelog-unseen-2026-10-13-older')
        ->assertMissing('@changelog-unseen-2026-10-14-newest');

    $page->click('@changelog-item-2026-10-13-older');
    waitForChangelogText($page, 'changelog-entry-title', 'Older feature');
    waitForChangelogTestIdGone($page, 'changelog-card');

    expect(ChangelogEntryView::where('user_id', $user->id)->pluck('changelog_entry_id')->sort()->values()->all())
        ->toEqual(collect([$newest->id, $older->id])->sort()->values()->all());

    $page->click('@changelog-close');
    waitForChangelogTestIdGone($page, 'changelog-dialog');

    $page->assertMissing('@changelog-card')
        ->assertMissing('@changelog-dialog')
        ->assertNoJavaScriptErrors();
});
