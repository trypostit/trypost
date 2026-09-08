<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForGridTestId(mixed $page, string $testId): void
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

function gridOwnerWithLinkedIn(): User
{
    $user = User::factory()->create();

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => 'li-connected',
    ]);

    return $user->fresh();
}

test('a taken network offers no second card when multiples are disabled', function () {
    config(['trypost.allow_multiple_social_accounts' => false]);

    $this->actingAs(gridOwnerWithLinkedIn());

    $page = visit(route('app.accounts'));

    waitForGridTestId($page, 'connect-x');

    $page->assertVisible('@connect-x')
        ->assertMissing('@connect-linkedin')
        ->assertNoJavaScriptErrors();
});

test('a taken network offers another card when multiples are allowed', function () {
    config(['trypost.allow_multiple_social_accounts' => true]);

    $this->actingAs(gridOwnerWithLinkedIn());

    $page = visit(route('app.accounts'));

    waitForGridTestId($page, 'connect-linkedin');

    $page->assertVisible('@connect-linkedin')
        ->assertVisible('@connect-x')
        ->assertNoJavaScriptErrors();
});

test('a lost connection offers both reconnect and disconnect', function () {
    $user = gridOwnerWithLinkedIn();

    $account = SocialAccount::factory()->x()->tokenExpired()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform_user_id' => 'x-expired',
    ]);

    $this->actingAs($user);

    $page = visit(route('app.accounts'));

    waitForGridTestId($page, "disconnect-{$account->id}");

    $page->assertVisible("@reconnect-{$account->id}")
        ->assertVisible("@disconnect-{$account->id}")
        ->assertNoJavaScriptErrors();
});
