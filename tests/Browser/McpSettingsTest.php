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

test('the MCP settings offer Claude, ChatGPT and other apps, with an illustrated empty state', function () {
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
    waitForMcpSettingsTestId($page, 'mcp-primary-client-claude');

    $page->assertVisible('@mcp-connected-empty')
        ->assertVisible('@mcp-empty-illustration')
        ->assertSeeIn('@mcp-connected-empty', __('mcp.connected_empty_title'))
        ->assertScript('document.querySelectorAll("[data-testid=mcp-connected-empty] button, [data-testid=mcp-connected-empty] a").length', 0)
        ->assertScript('[...document.querySelectorAll("[data-testid^=mcp-primary-client-]")].map((row) => row.dataset.testid)', ['mcp-primary-client-claude', 'mcp-primary-client-chatgpt'])
        ->assertVisible('@mcp-other-apps');

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

    $page->click('@mcp-other-apps');
    waitForMcpSettingsTestId($page, 'mcp-other-config');

    $page->assertSeeIn('@mcp-other-name', 'TryPost')
        ->assertSeeIn('@mcp-other-url', route('mcp.trypost'))
        ->assertSeeIn('@mcp-other-config', '"mcpServers"')
        ->assertSeeIn('@mcp-other-config', route('mcp.trypost'))
        ->assertNoJavaScriptErrors();
});
