<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

function mcpPromptUser(array $attributes = []): User
{
    config(['trypost.self_hosted' => false]);

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

test('a new user is invited to connect an AI, and Not now or the close button hide it for good in that browser', function (string $dismiss) {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');

    expect(mcpPromptAvatars($page))->toBe(['chatgpt', 'claude', 'grok', 'gemini', 'cursor', 'n8n'])
        ->and($page->script("(() => { const layers = [...document.querySelectorAll('[data-testid^=\"mcp-prompt-avatar-\"]')].map((avatar) => Number(getComputedStyle(avatar).zIndex)); return layers.every((layer, index) => index === 0 || layer < layers[index - 1]); })()"))->toBeTrue();

    $page->assertSeeIn('@mcp-prompt', __('mcp.prompt.title'))
        ->click($dismiss);
    waitForGone($page, '@mcp-prompt');

    $page->navigate(route('app.posts.index'));
    waitForPath($page, (string) parse_url(route('app.posts.index'), PHP_URL_PATH));
    waitFor($page, '@app-header');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
})->with(['not now' => '@mcp-prompt-dismiss', 'close' => '@mcp-prompt-close']);

test('the Claude button opens its steps on the page, which close once the AI connects', function () {
    $user = mcpPromptUser();
    $this->actingAs($user);
    $calendarPath = (string) parse_url(route('app.calendar'), PHP_URL_PATH);

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

test('Other apps opens the steps for any app that supports MCP', function () {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt-connect-other');
    $page->click('@mcp-prompt-connect-other');
    waitFor($page, '@mcp-dialog');

    $page->assertSeeIn('@mcp-dialog', __('mcp.other_dialog_title'))
        ->assertNoJavaScriptErrors();
});

test('the invitation waits while the user is asked where they found us', function () {
    $this->actingAs(mcpPromptUser(['referral_source' => null, 'created_at' => now()->subDays(2)]));

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@referral-source-survey');

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
