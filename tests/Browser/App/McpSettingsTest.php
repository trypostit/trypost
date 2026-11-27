<?php

declare(strict_types=1);

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

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
    waitFor($page, "@mcp-connected-menu-{$clientId}");

    $page->assertAttribute('@mcp-tab-connected', 'aria-selected', 'true')
        ->assertVisible('@mcp-connected-logo-claude');

    $page->click("@mcp-connected-menu-{$clientId}");
    waitFor($page, "@mcp-disconnect-{$clientId}");

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

test('a connected app shows its brand logo, and one without a logo shows the MCP mark', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $cursor = mcpOauthClient('Cursor');
    $unknown = mcpOauthClient('Muse Code');
    mcpAccessToken($user, $cursor, $workspace);
    mcpAccessToken($user, $unknown, $workspace);

    $this->actingAs($user->fresh());

    $page = visit(route('app.mcp.index'));
    waitFor($page, "@mcp-connected-client-{$unknown}");

    $page->assertVisible("[data-testid=\"mcp-connected-client-{$cursor}\"] [data-testid=\"mcp-connected-logo-cursor\"]")
        ->assertPresent("[data-testid=\"mcp-connected-client-{$unknown}\"] svg.tabler-icon-mcp")
        ->assertNoJavaScriptErrors();
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
    waitFor($page, '@mcp-connected-empty');

    $page->assertAttribute('@mcp-tab-connected', 'aria-selected', 'true')
        ->assertVisible('@mcp-empty-illustration')
        ->assertSeeIn('@mcp-connected-empty', __('mcp.connected_empty_title'))
        ->assertSeeIn('@mcp-connected-empty-connect', __('mcp.tabs.connect'))
        ->click('@mcp-connected-empty-connect');
    waitFor($page, '@mcp-connect-claude');

    expect(mcpSettingsTestIds($page, '[data-testid^=mcp-primary-client-]'))->toBe(['mcp-primary-client-claude', 'mcp-primary-client-chatgpt', 'mcp-primary-client-other']);

    $page->assertAttribute('@mcp-tab-connect', 'aria-selected', 'true')
        ->assertSeeIn('@settings-page-learn-more', __('common.learn_more'))
        ->assertAttribute('@settings-page-learn-more', 'href', 'https://docs.trypost.it/ai/introduction');

    $page->click('@mcp-connect-claude');
    waitFor($page, '@mcp-dialog-waiting');

    $page->assertSeeIn('@mcp-dialog', 'Connect TryPost to Claude')
        ->assertSeeIn('@mcp-dialog-url', route('mcp.trypost'))
        ->keys('@mcp-dialog', 'Escape');

    waitForGone($page, '@mcp-dialog');

    $page->click('@mcp-connect-other');
    waitFor($page, '@mcp-dialog-config');

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
    waitFor($page, '@mcp-connected-empty-connect');

    $page->click('@mcp-connected-empty-connect');
    waitFor($page, '@mcp-connect-chatgpt');

    $page->click('@mcp-connect-chatgpt');
    waitFor($page, '@mcp-dialog-waiting');

    $clientId = mcpOauthClient('ChatGPT');
    mcpAccessToken($user, $clientId, $workspace);

    waitFor($page, '@confetti-canvas');
    $page->assertPresent('@confetti-canvas');
    waitFor($page, "@mcp-connected-client-{$clientId}");
    waitForGone($page, '@mcp-dialog');

    $page->assertVisible("@mcp-connected-client-{$clientId}")
        ->assertNotPresent('@mcp-dialog')
        ->assertAttribute('@mcp-tab-connected', 'aria-selected', 'true')
        ->assertNoJavaScriptErrors();
});
