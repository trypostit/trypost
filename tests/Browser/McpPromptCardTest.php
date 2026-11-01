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

test('a new user is invited to connect an AI, and Not now hides it for good in that browser', function () {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');

    $page->assertSeeIn('@mcp-prompt', __('mcp.prompt.title'))
        ->click('@mcp-prompt-dismiss');
    waitForGone($page, '@mcp-prompt');

    $page->navigate(route('app.posts.index'));
    waitForPath($page, (string) parse_url(route('app.posts.index'), PHP_URL_PATH));
    waitFor($page, '@app-header');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});

test('connecting from the invitation opens the MCP settings on Connect an app, without the card', function () {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt-connect');
    $page->click('@mcp-prompt-connect');
    waitForPath($page, (string) parse_url(route('app.mcp.index'), PHP_URL_PATH));
    waitForState($page, '@mcp-tab-connect', "element.getAttribute('aria-selected') === 'true'");

    $page->assertAttribute('@mcp-tab-connect', 'aria-selected', 'true')
        ->assertVisible('@mcp-connect-claude')
        ->assertMissing('@mcp-prompt')
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
