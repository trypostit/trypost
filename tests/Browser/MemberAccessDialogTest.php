<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;

function waitForMemberAccessTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForMemberAccessGone(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (!document.querySelector('[data-testid="{$testId}"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

/**
 * @return array{0: User, 1: Workspace}
 */
function memberAccessOwner(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id, 'account_id' => $owner->account_id]);
    $workspace->members()->attach($owner->id, membershipPivot('admin'));
    $owner->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($owner->account);

    return [$owner->fresh(), $workspace];
}

test('the admin switch hides the publishing choice and the invite stores both flags', function () {
    [$owner] = memberAccessOwner();
    $this->actingAs($owner);

    $page = visit(route('app.members'));
    waitForMemberAccessTestId($page, 'invite-member-button');
    $page->click('@invite-member-button');
    waitForMemberAccessTestId($page, 'member-access-publishing');

    $page->assertScript('(() => { const row = document.querySelector("[data-testid=member-access-admin-row]").getBoundingClientRect(); const toggle = document.querySelector("[data-testid=member-access-admin]").getBoundingClientRect(); return Math.abs((row.top + row.bottom) / 2 - (toggle.top + toggle.bottom) / 2) < 1; })()', true);

    $page->click('@member-access-admin');
    waitForMemberAccessGone($page, 'member-access-publishing');
    $page->assertMissing('@member-access-publishing')
        ->click('@member-access-admin');
    waitForMemberAccessTestId($page, 'member-access-publishing-trigger');

    $page->click('@member-access-publishing-trigger');
    waitForMemberAccessTestId($page, 'member-access-publishing-approval');
    $page->click('@member-access-publishing-approval')
        ->fill('@invite-email', 'needs-approval@example.com');

    expect($page->script('document.querySelector("[data-testid=invite-member-cancel]").compareDocumentPosition(document.querySelector("[data-testid=invite-member-submit]")) & Node.DOCUMENT_POSITION_FOLLOWING'))->toBeGreaterThan(0);

    $page->click('@invite-member-submit');
    waitForMemberAccessGone($page, 'invite-member-dialog');

    $invite = Invite::query()->where('email', 'needs-approval@example.com')->sole();

    expect($invite->is_admin)->toBeFalse()
        ->and($invite->requires_approval)->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('editing a member saves the publishing choice and labels the member', function () {
    [$owner, $workspace] = memberAccessOwner();
    $member = workspaceMember($workspace, 'member', ['name' => 'Mia Member']);
    $this->actingAs($owner);

    $page = visit(route('app.members'));
    waitForMemberAccessTestId($page, "member-menu-{$member->id}");
    $page->assertMissing("@member-menu-{$owner->id}")
        ->click("@member-menu-{$member->id}");
    waitForMemberAccessTestId($page, "member-edit-{$member->id}");
    $page->click("@member-edit-{$member->id}");
    waitForMemberAccessTestId($page, 'member-access-publishing-trigger');

    $page->assertSeeIn('@edit-member-dialog', 'Mia Member')
        ->click('@member-access-publishing-trigger');
    waitForMemberAccessTestId($page, 'member-access-publishing-approval');
    $page->click('@member-access-publishing-approval');

    expect($page->script('document.querySelector("[data-testid=edit-member-cancel]").compareDocumentPosition(document.querySelector("[data-testid=edit-member-submit]")) & Node.DOCUMENT_POSITION_FOLLOWING'))->toBeGreaterThan(0);

    $page->click('@edit-member-submit');
    waitForMemberAccessGone($page, 'edit-member-dialog');

    $pivot = $workspace->members()->where('user_id', $member->id)->first()->pivot;

    expect((bool) $pivot->is_admin)->toBeFalse()
        ->and((bool) $pivot->requires_approval)->toBeTrue();

    $page->assertSeeIn("@member-badge-{$member->id}", 'Needs approval')
        ->assertNoJavaScriptErrors();
});

test('the member access copy fits on one line in every locale', function () {
    [$owner] = memberAccessOwner();
    $this->actingAs($owner);

    $page = visit(route('app.members'));
    waitForMemberAccessTestId($page, 'invite-member-button');
    $page->click('@invite-member-button');
    waitForMemberAccessTestId($page, 'member-access-publishing-trigger');

    $testIds = ['member-access-admin-label', 'member-access-admin-description', 'member-access-publishing-label', 'member-access-publishing-trigger', 'invite-member-cancel', 'invite-member-submit'];
    $idsJson = json_encode($testIds);
    $texts = $page->script("{$idsJson}.map((id) => document.querySelector('[data-testid=\"' + id + '\"]').textContent.trim())");

    $keysByText = [];

    foreach (glob(lang_path('en/*.php')) ?: [] as $file) {
        foreach (Arr::dot(require $file) as $key => $value) {
            if (is_string($value)) {
                $keysByText[$value] ??= basename($file, '.php').".{$key}";
            }
        }
    }

    $translations = [];

    foreach ($testIds as $index => $testId) {
        $key = $keysByText[$texts[$index]] ?? null;
        expect($key)->not->toBeNull("no lang key for {$testId}");

        foreach (Locale::cases() as $locale) {
            $translations[$testId][$locale->value] = __($key, [], $locale->value);
        }
    }

    $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    $wrapped = $page->script(<<<JS
        (() => {
            const translations = {$json};
            const failures = [];

            for (const [testId, byLocale] of Object.entries(translations)) {
                const element = document.querySelector('[data-testid="' + testId + '"]');
                const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
                const textNodes = [];
                while (walker.nextNode()) {
                    if (walker.currentNode.textContent.trim()) textNodes.push(walker.currentNode);
                }
                const node = textNodes[0];
                const original = node.textContent;

                for (const [locale, text] of Object.entries(byLocale)) {
                    node.textContent = text;
                    const range = document.createRange();
                    range.selectNodeContents(node);
                    const tops = new Set([...range.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top)));
                    if (tops.size > 1) failures.push(locale + ': ' + text);
                }

                node.textContent = original;
            }

            return failures;
        })()
    JS);

    expect($wrapped)->toBe([]);
    $page->assertNoJavaScriptErrors();
});
