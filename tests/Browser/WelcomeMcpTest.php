<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

function waitForWelcomeMcpTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function welcomeMcpSubscriber(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user->fresh();
}

test('the MCP step shows the prompt, waits for a connection and can be skipped', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'));
    waitForWelcomeMcpTestId($page, 'welcome-mcp-prompt');

    $page->assertSee('Connect TryPost to your AI')
        ->assertSeeIn('@welcome-mcp-prompt', route('mcp.trypost'))
        ->assertVisible('@welcome-mcp-copy')
        ->assertVisible('@welcome-mcp-open-claude')
        ->assertVisible('@welcome-mcp-open-chatgpt')
        ->assertAttribute('@welcome-mcp-open-claude', 'href', 'https://claude.ai/new?modal=add-custom-connector#customize/connectors/yours')
        ->assertAttribute('@welcome-mcp-open-chatgpt', 'href', 'https://chatgpt.com/plugins')
        ->assertSeeIn('@welcome-mcp-status', 'Waiting for your AI to connect')
        ->assertMissing('@welcome-mcp-continue');

    $page->script('window.__copied = []; navigator.clipboard.writeText = async (text) => { window.__copied.push(text); }; true;');
    $page->click('@welcome-mcp-copy');

    $copy = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                const label = document.querySelector('[data-testid="welcome-mcp-copy"]').textContent.trim();
                if (label !== 'Copy prompt') return { label, toasts: document.querySelectorAll('[data-sonner-toast]').length };
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })()
    JS);

    expect($copy)->toBe(['label' => 'Copied', 'toasts' => 0])
        ->and($page->script('window.__copied[0].includes('.json_encode(route('mcp.trypost')).')'))->toBeTrue();

    $page->click('@welcome-mcp-skip');

    $schedulePath = parse_url(route('app.posts.index'), PHP_URL_PATH);
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (window.location.pathname === '{$schedulePath}') return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertPathIs($schedulePath)
        ->assertNoJavaScriptErrors();
});
