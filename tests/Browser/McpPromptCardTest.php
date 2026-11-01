<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

function mcpPromptUser(array $attributes = []): User
{
    config(['trypost.self_hosted' => false]);
    showMcpPrompt();

    $user = User::factory()->create($attributes);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user->fresh();
}

/**
 * @return list<string>
 */
function mcpPromptAvatars(mixed $page): array
{
    return $page->script("[...document.querySelectorAll('[data-testid^=\"mcp-prompt-avatar-\"]')].map((avatar) => avatar.dataset.testid.replace('mcp-prompt-avatar-', ''))");
}

test('a new user is invited to connect an AI, and closing it hides it for good in that browser', function () {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');

    expect(mcpPromptAvatars($page))->toBe(['chatgpt', 'claude', 'grok', 'gemini', 'cursor', 'n8n'])
        ->and($page->script("(() => { const layers = [...document.querySelectorAll('[data-testid^=\"mcp-prompt-avatar-\"]')].map((avatar) => Number(getComputedStyle(avatar).zIndex)); return layers.every((layer, index) => index === 0 || layer < layers[index - 1]); })()"))->toBeTrue();

    $page->assertSeeIn('@mcp-prompt', __('mcp.prompt.title'))
        ->click('@mcp-prompt-close');
    waitForGone($page, '@mcp-prompt');

    $page->navigate(route('app.posts.index'));
    waitForPath($page, route('app.posts.index', absolute: false));
    waitFor($page, '@app-header');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});

test('closing the card keeps it in the user\'s one browser storage key, leaving the workspaces block as it was', function () {
    $user = mcpPromptUser();
    $this->actingAs($user);
    $key = "trypost:{$user->id}";

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');
    $page->script(sprintf("localStorage.setItem('%s', JSON.stringify({ version: 1, user: {}, workspaces: { kept: { note: true } } }))", $key));
    $page->click('@mcp-prompt-close');
    waitForGone($page, '@mcp-prompt');

    expect($page->script("Object.keys(localStorage).filter((key) => key.startsWith('trypost:') || key.startsWith('mcp.'))"))->toBe([$key])
        ->and($page->script("JSON.parse(localStorage.getItem('{$key}'))"))->toBe([
            'version' => 1,
            'user' => ['mcp.promptDismissed' => true],
            'workspaces' => ['kept' => ['note' => true]],
        ]);
    $page->assertNoJavaScriptErrors();
});

test('closing the card in another tab hides it here at once', function () {
    $user = mcpPromptUser();
    $this->actingAs($user);
    $key = "trypost:{$user->id}";

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');
    $page->script(sprintf(<<<'JS'
        (() => {
            const value = JSON.stringify({ version: 1, user: { 'mcp.promptDismissed': true }, workspaces: {} });
            localStorage.setItem('%1$s', value);
            window.dispatchEvent(new StorageEvent('storage', { key: '%1$s', newValue: value }));
        })()
    JS, $key));
    waitForGone($page, '@mcp-prompt');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});

test('an unreadable, older or malformed browser storage value reads as empty and is replaced on the next save', function (string $stored, array $workspaces = []) {
    $user = mcpPromptUser();
    $this->actingAs($user);
    $key = "trypost:{$user->id}";

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@app-header');
    $page->script(sprintf("localStorage.setItem('%s', %s)", $key, json_encode($stored)));
    $page->navigate(route('app.posts.index'));
    waitForPath($page, route('app.posts.index', absolute: false));
    waitFor($page, '@mcp-prompt');

    $page->click('@mcp-prompt-close');
    waitForGone($page, '@mcp-prompt');

    expect($page->script("JSON.parse(localStorage.getItem('{$key}'))"))->toBe([
        'version' => 1,
        'user' => ['mcp.promptDismissed' => true],
        'workspaces' => $workspaces,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'not JSON' => ['not json'],
    'an older version' => ['{"version":0,"user":{"mcp.promptDismissed":true},"workspaces":{}}'],
    'no user or workspaces block' => ['{"version":1}'],
    'an item of the wrong shape' => ['{"version":1,"user":{"mcp.promptDismissed":"yes"},"workspaces":{}}'],
    'a list instead of the value' => ['[1]'],
    'a user block that is not an object' => ['{"version":1,"user":"dismissed","workspaces":{}}'],
    'a workspace block that is not an object' => ['{"version":1,"user":{},"workspaces":{"workspace-a":"draft"}}', ['workspace-a' => []]],
]);

test('a browser that refuses storage still shows the card and closes it, on the defaults', function () {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@app-header');
    $page->script(<<<'JS'
        (() => {
            const refuse = () => { throw new DOMException('denied', 'SecurityError'); };
            Storage.prototype.getItem = refuse;
            Storage.prototype.setItem = refuse;
        })()
    JS);
    $page->click('@sidebar-logo');
    waitForPath($page, route('app.posts.index', absolute: false));
    waitFor($page, '@mcp-prompt');

    expect($page->script("(() => { try { localStorage.getItem('x'); return 'allowed'; } catch { return 'refused'; } })()"))->toBe('refused');

    $page->click('@mcp-prompt-close');
    waitForGone($page, '@mcp-prompt');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});

test('the Claude button opens its steps on the page, which close once the AI connects', function () {
    $user = mcpPromptUser();
    $this->actingAs($user);
    $calendarPath = route('app.calendar', absolute: false);

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt-connect-claude');
    $page->click('@mcp-prompt-connect-claude');
    waitFor($page, '@mcp-dialog');

    $page->assertSeeIn('@mcp-dialog', __('mcp.dialog.title', ['client' => 'Claude']))
        ->assertSeeIn('@mcp-dialog', route('mcp.trypost'))
        ->assertPathIs($calendarPath);

    mcpAccessToken($user, mcpOauthClient('Claude'), $user->currentWorkspace);
    waitForGone($page, '@mcp-dialog');

    $page->assertMissing('@mcp-dialog')
        ->assertMissing('@mcp-prompt')
        ->assertPathIs($calendarPath)
        ->assertNoJavaScriptErrors();
});

test('an AI connected from anywhere else hides the card on its own, without a click', function () {
    $user = mcpPromptUser();
    $this->actingAs($user);

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');

    mcpAccessToken($user, mcpOauthClient('Cursor'), $user->currentWorkspace);
    waitForGone($page, '@mcp-prompt');

    $page->assertMissing('@mcp-prompt')
        ->assertMissing('@mcp-dialog')
        ->assertNoJavaScriptErrors();
});

test('the steps stay open while nothing connected, even when the account loses access meanwhile', function () {
    $user = mcpPromptUser();
    $this->actingAs($user);

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt-connect-chatgpt');
    $page->click('@mcp-prompt-connect-chatgpt');
    waitFor($page, '@mcp-dialog');

    $user->account->subscriptions()->delete();
    $page->script('new Promise((resolve) => setTimeout(resolve, 4500))');

    $page->assertVisible('@mcp-dialog')
        ->assertNoJavaScriptErrors();
});

test('the invitation waits while the user is asked where they found us', function () {
    $this->actingAs(mcpPromptUser(['referral_source' => null, 'created_at' => now()->subDays(2)]));

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@referral-source-survey');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});

test('a user with an AI connected is never invited', function () {
    $user = mcpPromptUser();
    mcpAccessToken($user, mcpOauthClient('Claude'), $user->currentWorkspace);
    $this->actingAs($user);

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@app-content-shell');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});

test('a phone never shows the invitation', function () {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->on()->mobile();
    waitFor($page, '@app-header');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});
