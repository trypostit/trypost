<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

function waitForApiKeyCreateTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 150; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('the expiry date picker matches the dialog inputs and opens a calendar', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.api-keys.index'));
    waitForApiKeyCreateTestId($page, 'api-keys-empty-create');
    $page->click('@api-keys-empty-create');
    waitForApiKeyCreateTestId($page, 'token-expires-trigger');

    $page->assertScript(
        "(() => { const a = document.getElementById('token-name').getBoundingClientRect(); const b = document.getElementById('token-expires').getBoundingClientRect(); return Math.round(a.height) === Math.round(b.height) && getComputedStyle(document.getElementById('token-expires')).fontWeight === '400'; })()",
        true,
    )->assertSeeIn('@token-expires-trigger', __('settings.api_keys.create_dialog.expires_placeholder'));

    $page->click('@token-expires-trigger');
    waitForApiKeyCreateTestId($page, 'token-expires-calendar');

    $page->assertVisible('@token-expires-calendar')->assertNoJavaScriptErrors();
});

test('a generated key shows only its last four characters and copies without a toast', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.api-keys.index'));
    waitForApiKeyCreateTestId($page, 'api-keys-empty-create');
    $page->click('@api-keys-empty-create');
    waitForApiKeyCreateTestId($page, 'token-name');
    $page->fill('@token-name', 'Generated key');
    $page->click('@create-api-key-submit');
    waitForApiKeyCreateTestId($page, 'api-key-generated-dialog');

    $page->assertVisible('@api-key-generated-input')
        ->assertScript("(() => { const dialog = document.querySelector('[data-testid=\"api-key-generated-dialog\"]'); const field = document.querySelector('[data-testid=\"api-key-generated-input\"]'); return dialog.scrollWidth <= dialog.clientWidth && field.getBoundingClientRect().right <= dialog.getBoundingClientRect().right; })()", true)
        ->assertScript("document.querySelector('[data-testid=\"api-key-generated-dialog\"] [data-slot=\"dialog-close\"]') === null", true);

    $page->script('navigator.clipboard.writeText = async () => {};');
    $page->click('@api-key-generated-copy');
    waitForApiKeyCreateTestId($page, 'api-key-generated-copied');

    $page->assertVisible('@api-key-generated-copied')
        ->assertScript("document.querySelector('[data-sonner-toast]') === null", true)
        ->assertNoJavaScriptErrors();
});

function apiKeysPageAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('a workspace without api keys shows only the centered illustration with a create button', function () {
    $this->actingAs(apiKeysPageAdmin());

    $page = visit(route('app.api-keys.index'));
    waitForApiKeyCreateTestId($page, 'api-keys-empty');

    $page->assertVisible('@api-keys-empty-illustration')
        ->assertSeeIn('@api-keys-empty', __('settings.api_keys.empty.title'))
        ->assertMissing('@header-title')
        ->assertMissing('@create-api-key-button');

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]').getBoundingClientRect();
            const empty = document.querySelector('[data-testid="api-keys-empty"]').getBoundingClientRect();
            return Math.abs((empty.top + empty.height / 2) - (scroller.top + scroller.height / 2)) <= 24
                && Math.abs((empty.left + empty.width / 2) - (scroller.left + scroller.width / 2)) <= 24;
        })()
    JS))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('a workspace with api keys keeps the header and the list at the top', function () {
    $user = apiKeysPageAdmin();
    $result = $user->createToken('Existing key');
    AccessToken::find($result->token->id)->forceFill(['workspace_id' => $user->current_workspace_id])->saveQuietly();
    $this->actingAs($user);

    $page = visit(route('app.api-keys.index'));
    waitForApiKeyCreateTestId($page, 'header-title');

    $page->assertVisible('@create-api-key-button')
        ->assertMissing('@api-keys-empty')
        ->assertMissing('@settings-centered')
        ->assertNoJavaScriptErrors();
});

test('api key rows show whether each key is active, expiring soon or expired', function () {
    $user = apiKeysPageAdmin();
    $make = function (string $name, $expiresAt) use ($user): AccessToken {
        $result = $user->createToken($name);
        $token = AccessToken::find($result->token->id);
        $token->forceFill(['workspace_id' => $user->current_workspace_id, 'expires_at' => $expiresAt])->saveQuietly();

        return $token;
    };
    $active = $make('Active key', null);
    $soon = $make('Soon key', now()->addDays(2));
    $expired = $make('Expired key', now()->subDays(2));
    $this->actingAs($user);

    $page = visit(route('app.api-keys.index'));
    waitForApiKeyCreateTestId($page, "api-key-row-{$expired->id}");

    $page->assertSeeIn("@api-key-expiry-{$active->id}", __('settings.api_keys.meta.never_expires'));

    expect($page->script(<<<JS
        (() => ['{$active->id}', '{$soon->id}', '{$expired->id}'].map((id) =>
            document.querySelector(`[data-testid="api-key-status-dot-\${id}"]`).className.match(/bg-(success|warning|destructive)/)[1]
        ))()
    JS))->toBe(['success', 'warning', 'destructive']);

    $page->assertNoJavaScriptErrors();
});
