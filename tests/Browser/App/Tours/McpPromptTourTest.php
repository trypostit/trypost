<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

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
    return elementsMap($page, '@mcp-prompt-avatars [data-testid]', "element.dataset.testid.replace('mcp-prompt-avatar-', '')");
}

test('a new user is invited to connect an AI, and closing it hides it for good in that browser', function () {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');

    $layers = elementsMap($page, '@mcp-prompt-avatars [data-testid]', 'Number(getComputedStyle(element).zIndex)');

    expect(mcpPromptAvatars($page))->toBe(['chatgpt', 'claude', 'grok', 'gemini', 'cursor', 'n8n'])
        ->and($layers)->toBe(collect($layers)->sortDesc()->values()->all())
        ->and(array_unique($layers))->toHaveCount(count($layers));

    $page->assertSeeIn('@mcp-prompt', __('tours.mcp.prompt.steps.card.title'))
        ->click('@mcp-prompt-close');
    waitForGone($page, '@mcp-prompt');

    $page->navigate(route('app.posts.index'));
    waitForPath($page, route('app.posts.index', absolute: false));
    waitFor($page, '@app-header');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});

test('the card waits while a tour is open and shows once the tour closes', function () {
    config(['trypost.tours.enabled' => true]);
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');
    $page->assertMissing('@mcp-prompt');

    $page->click('@tour-publish-views-views-next');
    waitForGone($page, '@tour-publish-views-views-content');
    waitFor($page, '@mcp-prompt');

    $page->assertNoJavaScriptErrors();
});

test('the card shows once a tour open here is closed in another tab', function () {
    config(['trypost.tours.enabled' => true]);
    $user = mcpPromptUser();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForOpen($page, '@tour-publish-views-views-content');
    $page->assertMissing('@mcp-prompt');

    setBrowserStorageItem($page, $user, 'tours', ['publish.views' => ['status' => 'completed', 'step' => 1]]);
    announceBrowserStorageFromAnotherTab($page, $user);

    waitForGone($page, '@tour-backdrop');
    waitFor($page, '@mcp-prompt');

    $page->assertNoJavaScriptErrors();
});

test('answering where they found us opens the waiting tour first, and the card still waits for it', function () {
    config(['trypost.tours.enabled' => true]);
    $this->actingAs(mcpPromptUser(['referral_source' => null, 'created_at' => now()->subDays(2)]));

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitFor($page, '@referral-source-survey');
    $page->assertMissing('@mcp-prompt')
        ->assertMissing('@tour-publish-views-views-content');

    $page->click('@referral-source-option-google');
    $page->click('@referral-source-submit');
    waitForOpen($page, '@tour-publish-views-views-content');
    letPageRun($page, 1000);

    $page->assertMissing('@mcp-prompt');

    $page->click('@tour-publish-views-views-next');
    waitFor($page, '@mcp-prompt');

    $page->assertNoJavaScriptErrors();
});

test('closing the card keeps it in the user\'s one browser storage key, leaving the workspaces block as it was', function () {
    $user = mcpPromptUser();
    $this->actingAs($user);

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');
    setBrowserStorageItem($page, $user, 'note', true, 'kept');
    $page->click('@mcp-prompt-close');
    waitForGone($page, '@mcp-prompt');

    $appKeys = collect(localStorageKeys($page))->filter(fn (string $key): bool => Str::startsWith($key, ['trypost:', 'mcp.']))->values()->all();

    expect($appKeys)->toBe(["trypost:{$user->id}"])
        ->and(browserStorage($page, $user))->toBe([
            'version' => 1,
            'user' => ['tours' => ['mcp.prompt' => ['status' => 'skipped', 'step' => 1]]],
            'workspaces' => ['kept' => ['note' => true]],
        ]);
    $page->assertNoJavaScriptErrors();
});

test('closing the card in another tab hides it here at once', function () {
    $user = mcpPromptUser();
    $this->actingAs($user);

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@mcp-prompt');
    setBrowserStorageItem($page, $user, 'tours', ['mcp.prompt' => ['status' => 'skipped', 'step' => 1]]);
    announceBrowserStorageFromAnotherTab($page, $user);
    waitForGone($page, '@mcp-prompt');

    $page->assertMissing('@mcp-prompt')
        ->assertNoJavaScriptErrors();
});

test('an unreadable, older or malformed browser storage value reads as empty and is replaced on the next save', function (string $stored, array $workspaces = []) {
    $user = mcpPromptUser();
    $this->actingAs($user);

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@app-header');
    setRawBrowserStorage($page, $user, $stored);
    $page->navigate(route('app.posts.index'));
    waitForPath($page, route('app.posts.index', absolute: false));
    waitFor($page, '@mcp-prompt');

    $page->click('@mcp-prompt-close');
    waitForGone($page, '@mcp-prompt');

    expect(browserStorage($page, $user))->toBe([
        'version' => 1,
        'user' => ['tours' => ['mcp.prompt' => ['status' => 'skipped', 'step' => 1]]],
        'workspaces' => $workspaces,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'not JSON' => ['not json'],
    'an older version' => ['{"version":0,"user":{"tours":{"mcp.prompt":{"status":"skipped","step":1}}},"workspaces":{}}'],
    'no user or workspaces block' => ['{"version":1}'],
    'an item of the wrong shape' => ['{"version":1,"user":{"tours":"yes"},"workspaces":{}}'],
    'a list instead of the value' => ['[1]'],
    'a user block that is not an object' => ['{"version":1,"user":"dismissed","workspaces":{}}'],
    'a workspace block that is not an object' => ['{"version":1,"user":{},"workspaces":{"workspace-a":"draft"}}', ['workspace-a' => []]],
]);

test('a browser that refuses storage still shows the card and closes it, on the defaults', function () {
    $this->actingAs(mcpPromptUser());

    $page = visit(route('app.calendar'))->resize(1280, 900);
    waitFor($page, '@app-header');
    refuseBrowserStorage($page);
    $page->click('@sidebar-logo');
    waitForPath($page, route('app.posts.index', absolute: false));
    waitFor($page, '@mcp-prompt');

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
    letPageRun($page, 4500);

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
    connectAiTo($user);
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
