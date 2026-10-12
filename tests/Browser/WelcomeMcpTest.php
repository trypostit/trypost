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

function stubWelcomeMcpConnectTab(mixed $page): void
{
    $page->script(<<<'JS'
        window.__copied = [];
        navigator.clipboard.writeText = async (text) => { window.__copied.push(text); };
        document.addEventListener('click', (event) => {
            if (event.target.closest('a[target="_blank"]')) event.preventDefault();
        }, true);
        true;
    JS);
}

test('the MCP step opens a dialog with the steps and the app link, and can be skipped', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-connect-claude');

    expect($page->script('document.documentElement.scrollHeight > window.innerHeight'))->toBeFalse();

    expect($page->script('[...document.querySelectorAll("[data-testid^=welcome-mcp-benefit-]:not([data-testid*=-icon-])")].map((tab) => tab.dataset.testid.replace("welcome-mcp-benefit-", ""))'))->toBe(['ideas', 'schedule', 'queue', 'analytics']);

    $page->assertSee('Run your social media from a chat')
        ->assertSeeIn('@welcome-mcp-composer', 'Ask anything')
        ->assertVisible('@welcome-mcp-benefit-schedule')
        ->assertVisible('@welcome-mcp-benefit-ideas')
        ->click('@welcome-mcp-benefit-schedule');
    waitForWelcomeMcpChat($page, 'schedule');

    $page->assertSeeIn('@welcome-mcp-chat-schedule', 'Take the next idea on my board')
        ->assertSeeIn('@welcome-mcp-chat-schedule', 'schedule it for Tuesday at 10:00 AM')
        ->assertScript('document.querySelector("[data-testid=welcome-mcp-chat-schedule]").innerText.split("\\n").includes("Scheduled")', true)
        ->assertScript('[...document.querySelectorAll("[data-testid=welcome-mcp-chat-schedule] [data-testid^=welcome-mcp-schedule-post-]")].map((post) => post.dataset.testid)', ['welcome-mcp-schedule-post-instagram', 'welcome-mcp-schedule-post-linkedin'])
        ->click('@welcome-mcp-benefit-analytics');
    waitForWelcomeMcpChat($page, 'analytics');

    $page->assertScript('getComputedStyle(document.querySelector("[data-testid=welcome-mcp-benefit-icon-analytics]")).backgroundColor !== getComputedStyle(document.querySelector("[data-testid=welcome-mcp-benefit-icon-queue]")).backgroundColor', true);

    $page->assertSeeIn('@welcome-mcp-chat-analytics', 'How was my month')
        ->assertSeeIn('@welcome-mcp-chat-analytics', 'Total Followers')
        ->click('@welcome-mcp-benefit-queue');
    waitForWelcomeMcpChat($page, 'queue');

    $page->assertScript('document.querySelector("[data-testid=welcome-mcp-chat-queue]").innerText.split("\\n").includes("Tomorrow")', true)
        ->assertSeeIn('@welcome-mcp-chat-queue', 'New')
        ->click('@welcome-mcp-benefit-ideas');
    waitForWelcomeMcpChat($page, 'ideas');

    $page->assertScript('document.querySelector("[data-testid=welcome-mcp-chat-ideas]").innerText.split("\\n").filter((line) => line === "New idea").length', 2);

    $page->assertSeeIn('@welcome-mcp-chat-ideas', 'To Do')
        ->assertSeeIn('@welcome-mcp-chat-ideas', 'In Progress');

    expect($page->script('document.querySelector("[data-testid=welcome-mcp-connect-claude]").getAttribute("href")'))->toBeNull()
        ->and($page->script('new Set([...document.querySelectorAll("[data-testid^=welcome-mcp-connect-]:not([data-testid=welcome-mcp-connect-ai])")].map((button) => Math.round(button.getBoundingClientRect().top))).size'))->toBe(1);

    $page->assertSeeIn('@welcome-mcp-connect-claude', 'Connect with Claude')
        ->assertMissing('@welcome-mcp-copy')
        ->assertMissing('@welcome-mcp-dialog')
        ->assertMissing('@welcome-mcp-continue');

    stubWelcomeMcpConnectTab($page);
    $page->click('@welcome-mcp-connect-claude');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-step-paste');

    expect($page->script('window.__copied'))->toBe([])
        ->and($page->script('document.activeElement.dataset.testid'))->toBe('welcome-mcp-dialog')
        ->and($page->script('getComputedStyle(document.activeElement).outlineStyle'))->toBe('none');

    $page->assertSeeIn('@welcome-mcp-name-copy', 'Copy')
        ->assertSeeIn('@welcome-mcp-url-copy', 'Copy')
        ->click('@welcome-mcp-name-copy');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (window.__copied.length) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertSeeIn('@welcome-mcp-name-copy', 'Copied')
        ->assertDontSeeIn('@welcome-mcp-url-copy', 'Copied');

    $page->click('@welcome-mcp-url-copy');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (window.__copied.length === 2) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertSeeIn('@welcome-mcp-dialog', 'Connect TryPost to Claude')
        ->assertSeeIn('@welcome-mcp-url', route('mcp.trypost'))
        ->assertSeeIn('@welcome-mcp-step-paste', "Paste the URL into the connector's URL field")
        ->assertVisible('@welcome-mcp-step-add')
        ->assertVisible('@welcome-mcp-step-login')
        ->assertSeeIn('@welcome-mcp-dialog-waiting', 'Waiting for your AI to connect')
        ->assertScript('document.querySelectorAll("[data-sonner-toast]").length', 0);

    expect($page->script('window.__copied'))->toBe(['TryPost', route('mcp.trypost')])
        ->and($page->script('document.querySelector("[data-testid=welcome-mcp-open]").getAttribute("href")'))->toStartWith('https://claude.ai/')
        ->and($page->script('document.querySelector("[data-testid=welcome-mcp-open]").getAttribute("target")'))->toBe('_blank');

    $page->assertSeeIn('@welcome-mcp-open', 'Open Claude')
        ->click('@welcome-mcp-open');

    expect($page->script('document.querySelector("[data-testid=welcome-mcp-dialog]") !== null'))->toBeTrue();

    $page->keys('@welcome-mcp-dialog', 'Escape');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (!document.querySelector('[data-testid="welcome-mcp-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->click('@welcome-mcp-connect-chatgpt');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-step-developer');

    $page->assertSeeIn('@welcome-mcp-dialog', 'Connect TryPost to ChatGPT')
        ->assertSeeIn('@welcome-mcp-step-developer', 'Turn on Developer mode')
        ->assertVisible('@welcome-mcp-step-create')
        ->assertMissing('@welcome-mcp-step-paste');

    expect($page->script('document.querySelector("[data-testid=welcome-mcp-open]").getAttribute("href")'))->toBe('https://chatgpt.com/plugins');

    $page->keys('@welcome-mcp-dialog', 'Escape');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (!document.querySelector('[data-testid="welcome-mcp-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->assertSeeIn('@welcome-mcp-connect-other', 'Connect another app')
        ->click('@welcome-mcp-connect-other');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-config');

    $page->assertSeeIn('@welcome-mcp-dialog', 'Connect TryPost to another app')
        ->assertVisible('@welcome-mcp-step-add')
        ->assertSeeIn('@welcome-mcp-config', route('mcp.trypost'))
        ->assertMissing('@welcome-mcp-open')
        ->keys('@welcome-mcp-dialog', 'Escape');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (!document.querySelector('[data-testid="welcome-mcp-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

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

test('on a phone the MCP step keeps the example and connects through an AI picker sheet', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'))->resize(390, 844);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-pill-schedule');

    $page->assertVisible('@welcome-mcp-pill-schedule')
        ->assertMissing('@welcome-mcp-benefit-schedule')
        ->click('@welcome-mcp-pill-ideas');
    waitForWelcomeMcpChat($page, 'ideas');

    $page->assertVisible('@welcome-mcp-chat-ideas')
        ->assertScript('document.querySelector("[data-testid=welcome-mcp-pill-ideas]").getAttribute("aria-selected")', 'true');

    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $footer = $page->script(<<<'JS'
        (async () => {
            window.scrollTo(0, document.documentElement.scrollHeight);
            await new Promise((resolve) => setTimeout(resolve, 300));
            const rect = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const skip = rect('welcome-mcp-skip-link');
            const connect = rect('welcome-mcp-connect-ai');
            return {
                compact: rect('welcome-actions').height < 120,
                windowAboveFooter: rect('welcome-mcp-window').bottom <= rect('welcome-actions').top,
                composerHidden: rect('welcome-mcp-composer').height === 0,
                connectBeforeSkip: Math.round(skip.top) === Math.round(connect.top) && connect.left < skip.left,
            };
        })()
    JS);

    expect($footer)->toBe(['compact' => true, 'windowAboveFooter' => true, 'composerHidden' => true, 'connectBeforeSkip' => true]);

    $page->assertSeeIn('@welcome-mcp-connect-ai', 'Connect your AI')
        ->assertVisible('@welcome-mcp-skip-link')
        ->assertMissing('@welcome-mcp-connect-claude')
        ->assertMissing('@welcome-mcp-connect-other')
        ->assertMissing('@welcome-mcp-skip');

    stubWelcomeMcpConnectTab($page);
    $page->click('@welcome-mcp-connect-ai');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-pick-other');

    $page->assertSeeIn('@welcome-mcp-ai-picker', 'Which AI do you use?')
        ->assertVisible('@welcome-mcp-pick-claude')
        ->assertVisible('@welcome-mcp-pick-chatgpt')
        ->click('@welcome-mcp-pick-other');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-config');

    $page->assertSeeIn('@welcome-mcp-dialog', 'Connect TryPost to another app')
        ->assertMissing('@welcome-mcp-ai-picker')
        ->keys('@welcome-mcp-dialog', 'Escape');
    $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (!document.querySelector('[data-testid="welcome-mcp-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);

    $page->click('@welcome-mcp-connect-ai');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-pick-claude');

    $page->click('@welcome-mcp-pick-claude');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-step-paste');

    $dialog = $page->script(<<<'JS'
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, 600));
            const rect = document.querySelector('[data-testid="welcome-mcp-dialog"]').getBoundingClientRect();
            return { bottom: Math.round(rect.bottom), width: Math.round(rect.width) };
        })()
    JS);

    expect($dialog)->toBe(['bottom' => 844, 'width' => 390]);

    $page->assertVisible('@welcome-mcp-open')
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
    waitForWelcomeMcpTestId($page, 'welcome-mcp-connect-claude');

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

    $page->assertScript('document.querySelector("[data-testid=welcome-mcp-chat-queue]").innerText.split("\\n").includes("Tomorrow")', true)
        ->assertNoJavaScriptErrors();
})->with([
    'with a photo' => [true],
    'without a photo' => [false],
]);

test('hovering a month of the follower example shows each network that month', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-connect-claude');

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

test('once the AI connects the dialog closes, confetti flies and the page offers to continue', function () {
    config(['trypost.self_hosted' => false]);

    $owner = welcomeMcpSubscriber();
    $this->actingAs($owner);

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-connect-claude');

    $page->click('@welcome-mcp-connect-claude');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-dialog-waiting');

    mcpAccessToken($owner, mcpOauthClient('Claude'), $owner->currentWorkspace);

    $celebration = $page->script(<<<'JS'
        (async () => {
            let confetti = false;
            for (let i = 0; i < 120; i++) {
                confetti ||= document.querySelector('[data-testid="confetti-canvas"]') !== null;
                if (confetti && document.querySelector('[data-testid="welcome-mcp-status"]') && !document.querySelector('[data-testid="welcome-mcp-dialog"]')) {
                    return 'celebrated';
                }
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return { confetti };
        })()
    JS);

    expect($celebration)->toBe('celebrated');

    $page->assertSeeIn('@welcome-mcp-status', 'Claude is connected')
        ->assertVisible('@welcome-mcp-continue')
        ->assertMissing('@welcome-mcp-connect-claude')
        ->assertMissing('@welcome-mcp-skip')
        ->assertScript('document.querySelector("[data-testid=welcome-mcp-continue]").getBoundingClientRect().top < document.querySelector("[data-testid=welcome-mcp-status]").getBoundingClientRect().top', true)
        ->assertNoJavaScriptErrors();
});
