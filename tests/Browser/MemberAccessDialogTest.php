<?php

declare(strict_types=1);

use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

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
