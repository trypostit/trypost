<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

function waitForMcpSettingsTestId(mixed $page, string $testId): void
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

test('disconnecting an MCP app asks for the translated disconnect keyword, not the app name', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $clientId = mcpOauthClient('Claude');
    mcpAccessToken($user, $clientId, $workspace);

    $this->actingAs($user->fresh());

    $keyword = __('channels.disconnect_modal.keyword');

    $page = visit(route('app.mcp.index'));
    waitForMcpSettingsTestId($page, "mcp-connected-menu-{$clientId}");

    $page->assertScript('document.querySelector("[data-testid=mcp-tab-connected]").getAttribute("aria-selected")', 'true');

    $page->click("@mcp-connected-menu-{$clientId}");
    waitForMcpSettingsTestId($page, "mcp-disconnect-{$clientId}");

    $page->click("@mcp-disconnect-{$clientId}")
        ->assertVisible('@confirm-delete-modal')
        ->assertScript('document.querySelector("[data-testid=confirm-delete-modal] code").textContent.trim()', $keyword)
        ->assertDontSeeIn('@confirm-delete-modal', 'Claude')
        ->fill('@confirm-delete-input', 'Claude')
        ->assertAttribute('@confirm-delete-action', 'disabled', '')
        ->fill('@confirm-delete-input', mb_strtoupper($keyword))
        ->assertAttribute('@confirm-delete-action', 'disabled', '')
        ->fill('@confirm-delete-input', $keyword)
        ->click('@confirm-delete-action')
        ->assertMissing('@confirm-delete-modal')
        ->assertNoJavaScriptErrors();

    expect(AccessToken::query()->where('client_id', $clientId)->where('revoked', false)->exists())->toBeFalse();
});

test('the MCP settings open on the connected tab, whose empty state leads to the connect tab', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $this->actingAs($user->fresh());

    $page = visit(route('app.mcp.index'));
    waitForMcpSettingsTestId($page, 'mcp-connected-empty');

    $page->assertScript('document.querySelector("[data-testid=mcp-tab-connected]").getAttribute("aria-selected")', 'true')
        ->assertVisible('@mcp-empty-illustration')
        ->assertSeeIn('@mcp-connected-empty', __('mcp.connected_empty_title'))
        ->assertSeeIn('@mcp-connected-empty-connect', __('mcp.tabs.connect'))
        ->click('@mcp-connected-empty-connect');
    waitForMcpSettingsTestId($page, 'mcp-connect-claude');

    $page->assertScript('document.querySelector("[data-testid=mcp-tab-connect]").getAttribute("aria-selected")', 'true')
        ->assertScript('[...document.querySelectorAll("[data-testid^=mcp-primary-client-]")].map((row) => row.dataset.testid)', ['mcp-primary-client-claude', 'mcp-primary-client-chatgpt', 'mcp-primary-client-other']);

    $page->click('@mcp-connect-claude');
    waitForMcpSettingsTestId($page, 'welcome-mcp-dialog-waiting');

    $page->assertSeeIn('@welcome-mcp-dialog', 'Connect TryPost to Claude')
        ->assertSeeIn('@welcome-mcp-url', route('mcp.trypost'))
        ->keys('@welcome-mcp-dialog', 'Escape');

    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (!document.querySelector('[data-testid="welcome-mcp-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->click('@mcp-connect-other');
    waitForMcpSettingsTestId($page, 'welcome-mcp-config');

    $page->assertSeeIn('@welcome-mcp-dialog', __('mcp.other_dialog_title'))
        ->assertVisible('@welcome-mcp-step-add')
        ->assertSeeIn('@welcome-mcp-name', 'TryPost')
        ->assertSeeIn('@welcome-mcp-config', '"mcpServers"')
        ->assertSeeIn('@welcome-mcp-config', route('mcp.trypost'))
        ->assertMissing('@welcome-mcp-open')
        ->assertNoJavaScriptErrors();
});

test('a new connection closes the connect dialog and shows the connected tab', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $this->actingAs($user->fresh());

    $page = visit(route('app.mcp.index'));
    waitForMcpSettingsTestId($page, 'mcp-connected-empty-connect');

    $page->click('@mcp-connected-empty-connect');
    waitForMcpSettingsTestId($page, 'mcp-connect-chatgpt');

    $page->click('@mcp-connect-chatgpt');
    waitForMcpSettingsTestId($page, 'welcome-mcp-dialog-waiting');

    $clientId = mcpOauthClient('ChatGPT');
    mcpAccessToken($user, $clientId, $workspace);

    $state = $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                const row = document.querySelector('[data-testid="mcp-connected-client-{$clientId}"]');
                if (row && row.getBoundingClientRect().height > 0 && !document.querySelector('[data-testid="welcome-mcp-dialog"]')) return 'shown';
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return 'missing';
        })()
    JS);

    expect($state)->toBe('shown');

    $page->assertScript('document.querySelector("[data-testid=mcp-tab-connected]").getAttribute("aria-selected")', 'true')
        ->assertNoJavaScriptErrors();
});
