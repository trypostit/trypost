<?php

declare(strict_types=1);

use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

function waitForWelcomeMcpChat(mixed $page, string $benefit): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                const chat = document.querySelector('[data-testid="welcome-mcp-chat-{$benefit}"]');
                if (chat && getComputedStyle(chat).opacity === '1' && !chat.className.includes('-active')) return;
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

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-open-claude');

    expect($page->script('document.documentElement.scrollHeight > window.innerHeight'))->toBeFalse();

    $page->assertSee('Post and analyze right from Claude and ChatGPT')
        ->assertVisible('@welcome-mcp-benefit-schedule')
        ->assertVisible('@welcome-mcp-benefit-ideas')
        ->click('@welcome-mcp-benefit-schedule');
    waitForWelcomeMcpChat($page, 'schedule');

    $page->assertSeeIn('@welcome-mcp-chat-schedule', 'Create a carousel about our sale')
        ->assertScript('document.querySelector("[data-testid=welcome-mcp-chat-schedule]").innerText.split("\\n").includes("Scheduled")', true)
        ->click('@welcome-mcp-benefit-analytics');
    waitForWelcomeMcpChat($page, 'analytics');

    $page->assertSeeIn('@welcome-mcp-chat-analytics', 'How are my followers growing')
        ->assertSeeIn('@welcome-mcp-chat-analytics', 'Total Followers')
        ->click('@welcome-mcp-benefit-queue');
    waitForWelcomeMcpChat($page, 'queue');

    $page->assertSeeIn('@welcome-mcp-chat-queue', 'Tomorrow')
        ->assertSeeIn('@welcome-mcp-chat-queue', 'New')
        ->click('@welcome-mcp-benefit-ideas');
    waitForWelcomeMcpChat($page, 'ideas');

    $page->assertScript('document.querySelector("[data-testid=welcome-mcp-chat-ideas]").innerText.split("\\n").filter((line) => line === "New idea").length', 2);

    $claudeHref = $page->script('document.querySelector("[data-testid=welcome-mcp-open-claude]").getAttribute("href")');
    $chatgptHref = $page->script('document.querySelector("[data-testid=welcome-mcp-open-chatgpt]").getAttribute("href")');

    expect($claudeHref)->toStartWith('https://claude.ai/new?q=')
        ->and(urldecode(parse_url($claudeHref, PHP_URL_QUERY)))->toContain(route('mcp.trypost'))
        ->and($chatgptHref)->toStartWith('https://chatgpt.com/?q=')
        ->and(urldecode(parse_url($chatgptHref, PHP_URL_QUERY)))->toContain(route('mcp.trypost'))
        ->and($page->script('document.querySelector("[data-testid=welcome-mcp-open-perplexity]").getAttribute("href")'))->toStartWith('https://www.perplexity.ai/?q=')
        ->and($page->script('document.querySelector("[data-testid=welcome-mcp-open-grok]").getAttribute("href")'))->toStartWith('https://grok.com/?q=')
        ->and($page->script('new Set([...document.querySelectorAll("[data-testid^=welcome-mcp-open-], [data-testid=welcome-mcp-copy]")].map((button) => Math.round(button.getBoundingClientRect().top))).size'))->toBe(1);

    $page->assertMissing('@welcome-mcp-status')
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

    $page->assertSeeIn('@welcome-mcp-status', 'Waiting for your AI to connect');

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

test('the MCP step fits a phone without scrolling and hides the chat example there', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'))->resize(390, 844);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-open-claude');

    $layout = $page->script(<<<'JS'
        ({
            scrolls: document.documentElement.scrollHeight > window.innerHeight,
            chatVisible: document.querySelector('[data-testid^="welcome-mcp-chat-"]')?.getClientRects().length > 0,
        })
    JS);

    expect($layout)->toBe(['scrolls' => false, 'chatVisible' => false]);

    $page->assertVisible('@welcome-mcp-copy')
        ->assertNoJavaScriptErrors();
});

test('the schedule and queue examples show the owner photo, or their initials without one', function (bool $withPhoto) {
    config(['trypost.self_hosted' => false]);

    $owner = welcomeMcpSubscriber();
    $owner->update(['name' => 'Ada Lovelace']);

    if ($withPhoto) {
        $path = 'avatars/'.Str::uuid().'.png';
        Storage::put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));
        Media::factory()->avatar()->create([
            'mediable_type' => $owner->getMorphClass(),
            'mediable_id' => $owner->id,
            'path' => $path,
            'mime_type' => 'image/png',
        ]);
    }

    $this->actingAs($owner->fresh());

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-open-claude');

    foreach (['schedule', 'queue'] as $benefit) {
        $page->click("@welcome-mcp-benefit-{$benefit}");
        waitForWelcomeMcpChat($page, $benefit);

        $avatar = $page->script(<<<JS
            (async () => {
                const chat = document.querySelector('[data-testid="welcome-mcp-chat-{$benefit}"]');
                for (let i = 0; i < 50; i++) {
                    const image = chat.querySelector('img[src*="avatars/"]');
                    if (image && image.complete) return 'photo';
                    await new Promise((resolve) => setTimeout(resolve, 50));
                }
                return chat.innerText.includes('AL') ? 'initials' : 'none';
            })()
        JS);

        expect($avatar)->toBe($withPhoto ? 'photo' : 'initials');
    }

    $page->assertSeeIn('@welcome-mcp-chat-queue', 'Tomorrow')
        ->assertNoJavaScriptErrors();
})->with([
    'with a photo' => [true],
    'without a photo' => [false],
]);

test('hovering a month of the follower example shows each network that month', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-open-claude');

    $page->click('@welcome-mcp-benefit-analytics');
    waitForWelcomeMcpChat($page, 'analytics');

    $page->assertMissing('@welcome-mcp-followers-tooltip')
        ->hover('[data-testid="welcome-mcp-chat-analytics"] [data-testid="welcome-mcp-followers-month-5"]');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-followers-tooltip');

    $page->assertSeeIn('@welcome-mcp-followers-tooltip', now()->format('F Y'))
        ->assertSeeIn('@welcome-mcp-followers-tooltip', '5,840')
        ->assertSeeIn('@welcome-mcp-followers-tooltip', '5,220')
        ->assertSeeIn('@welcome-mcp-followers-tooltip', '1,340')
        ->assertNoJavaScriptErrors();
});
