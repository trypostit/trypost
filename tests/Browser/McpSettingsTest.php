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
