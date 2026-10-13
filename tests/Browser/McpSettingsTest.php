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

function waitForMcpSettingsTestIdGone(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (!document.querySelector('[data-testid="{$testId}"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })()
    JS);
}

/**
 * @return list<string>
 */
function mcpSettingsTestIds(mixed $page, string $selector): array
{
    $encodedSelector = json_encode($selector);

    return $page->script("[...document.querySelectorAll({$encodedSelector})].map((element) => element.dataset.testid)");
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

    $page->assertAttribute('@mcp-tab-connected', 'aria-selected', 'true')
        ->assertVisible('@mcp-connected-logo-claude');

    $page->click("@mcp-connected-menu-{$clientId}");
    waitForMcpSettingsTestId($page, "mcp-disconnect-{$clientId}");

    $page->click("@mcp-disconnect-{$clientId}")
        ->assertVisible('@confirm-delete-modal');

    expect(trim($page->text('[data-testid="confirm-delete-modal"] code')))->toBe($keyword);

    $page->assertDontSeeIn('@confirm-delete-modal', 'Claude')
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

    $page->assertAttribute('@mcp-tab-connected', 'aria-selected', 'true')
        ->assertVisible('@mcp-empty-illustration')
        ->assertSeeIn('@mcp-connected-empty', __('mcp.connected_empty_title'))
        ->assertSeeIn('@mcp-connected-empty-connect', __('mcp.tabs.connect'))
        ->click('@mcp-connected-empty-connect');
    waitForMcpSettingsTestId($page, 'mcp-connect-claude');

    expect(mcpSettingsTestIds($page, '[data-testid^=mcp-primary-client-]'))->toBe(['mcp-primary-client-claude', 'mcp-primary-client-chatgpt', 'mcp-primary-client-other']);

    $page->assertAttribute('@mcp-tab-connect', 'aria-selected', 'true')
        ->assertSeeIn('@settings-page-learn-more', __('common.learn_more'))
        ->assertAttribute('@settings-page-learn-more', 'href', 'https://docs.trypost.it/ai/introduction');

    $page->click('@mcp-connect-claude');
    waitForMcpSettingsTestId($page, 'mcp-dialog-waiting');

    $page->assertSeeIn('@mcp-dialog', 'Connect TryPost to Claude')
        ->assertSeeIn('@mcp-dialog-url', route('mcp.trypost'))
        ->keys('@mcp-dialog', 'Escape');

    waitForMcpSettingsTestIdGone($page, 'mcp-dialog');

    $page->click('@mcp-connect-other');
    waitForMcpSettingsTestId($page, 'mcp-dialog-config');

    $page->assertSeeIn('@mcp-dialog', __('mcp.other_dialog_title'))
        ->assertVisible('@mcp-dialog-step-add')
        ->assertSeeIn('@mcp-dialog-name', 'TryPost')
        ->assertSeeIn('@mcp-dialog-config', '"mcpServers"')
        ->assertSeeIn('@mcp-dialog-config', route('mcp.trypost'))
        ->assertMissing('@mcp-dialog-open')
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
    waitForMcpSettingsTestId($page, 'mcp-dialog-waiting');

    $clientId = mcpOauthClient('ChatGPT');
    mcpAccessToken($user, $clientId, $workspace);

    waitForMcpSettingsTestId($page, "mcp-connected-client-{$clientId}");
    waitForMcpSettingsTestIdGone($page, 'mcp-dialog');

    $page->assertVisible("@mcp-connected-client-{$clientId}")
        ->assertNotPresent('@mcp-dialog')
        ->assertAttribute('@mcp-tab-connected', 'aria-selected', 'true')
        ->assertNoJavaScriptErrors();
});
