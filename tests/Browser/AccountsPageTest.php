<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 * Never `sleep()` here instead: the test server runs inside the PHP process, so
 * a blocking sleep starves the assets the page is trying to load.
 */
function waitForAccountsTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function accountsOwner(): User
{
    $user = User::factory()->create();

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

function accountsOwnerWithLinkedIn(): User
{
    $user = accountsOwner();

    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform_user_id' => 'li-connected',
    ]);

    return $user;
}

test('a workspace without accounts lists every network with a connect slot', function () {
    $this->actingAs(accountsOwner());

    $page = visit(route('app.accounts'));

    waitForAccountsTestId($page, 'network-group-linkedin');

    $page->assertVisible('@network-group-linkedin')
        ->assertVisible('@connect-linkedin')
        ->assertVisible('@connect-x')
        ->assertMissing('@connect-another-linkedin')
        ->assertVisible('@connect-account-button')
        ->assertNoJavaScriptErrors();
});

test('every network is listed, connected ones grouped with a slot for one more', function () {
    $user = accountsOwnerWithLinkedIn();

    SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform_user_id' => 'li-page',
    ]);

    $this->actingAs($user);

    $page = visit(route('app.accounts'));

    waitForAccountsTestId($page, 'network-group-linkedin');

    $page->assertVisible('@network-group-linkedin')
        ->assertVisible('@connect-another-linkedin')
        ->assertMissing('@connect-linkedin')
        ->assertVisible('@network-group-x')
        ->assertVisible('@connect-x')
        ->assertMissing('@connect-another-x')
        ->assertNoJavaScriptErrors();
});

test('the connect button opens the catalog with per-network counts', function () {
    $this->actingAs(accountsOwnerWithLinkedIn());

    $page = visit(route('app.accounts'));

    waitForAccountsTestId($page, 'connect-account-button');

    $page->click('@connect-account-button');

    waitForAccountsTestId($page, 'connect-account-dialog');

    $page->assertVisible('@connect-account-dialog')
        ->assertVisible('@connect-linkedin')
        ->assertSeeIn('@connect-count-linkedin', '1')
        ->assertMissing('@connect-count-x')
        ->assertNoJavaScriptErrors();
});

test('a lost connection offers reconnect on the card and disconnect in its menu', function () {
    $user = accountsOwnerWithLinkedIn();

    $account = SocialAccount::factory()->x()->tokenExpired()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform_user_id' => 'x-expired',
    ]);

    $this->actingAs($user);

    $page = visit(route('app.accounts'));

    waitForAccountsTestId($page, "reconnect-button-{$account->id}");

    $page->assertVisible("@reconnect-button-{$account->id}")
        ->click("@account-menu-{$account->id}");

    waitForAccountsTestId($page, "disconnect-{$account->id}");

    $page->assertVisible("@reconnect-{$account->id}")
        ->assertVisible("@disconnect-{$account->id}")
        ->assertNoJavaScriptErrors();
});
