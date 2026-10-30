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

function waitForWelcomeMcpTestIdGone(mixed $page, string $testId): void
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

function waitForWelcomeMcpPath(mixed $page, string $path): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (window.location.pathname === '{$path}') return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })()
    JS);
}

function waitForWelcomeMcpCopied(mixed $page, int $count): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 50; i++) {
                if (window.__copied.length >= {$count}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })()
    JS);
}

function waitForWelcomeMcpRequest(mixed $page, string $path, int $status): bool
{
    return $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 60; i++) {
                if (performance.getEntriesByType('resource').some((entry) => new URL(entry.name).pathname === '{$path}' && entry.responseStatus === {$status})) return true;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return false;
        })()
    JS);
}

function waitForWelcomeMcpCelebration(mixed $page): mixed
{
    return $page->script(<<<'JS'
        (async () => {
            let confetti = false;
            for (let i = 0; i < 120; i++) {
                confetti ||= document.querySelector('[data-testid="confetti-canvas"]') !== null;
                if (confetti && document.querySelector('[data-testid="welcome-mcp-status"]') && !document.querySelector('[data-testid="mcp-dialog"]')) {
                    return 'celebrated';
                }
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            return { confetti };
        })()
    JS);
}

function waitForWelcomeMcpBenefitChange(mixed $page, string $from): ?string
{
    return $page->script(<<<JS
        (async () => {
            const selected = () => document.querySelector('[data-testid^="welcome-mcp-benefit-"][aria-pressed="true"]')?.dataset.testid;
            for (let i = 0; i < 240; i++) {
                if (selected() !== '{$from}') return selected();
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })()
    JS);
}

function welcomeMcpPause(mixed $page, int $milliseconds): void
{
    $page->script(<<<JS
        (async () => {
            await new Promise((resolve) => setTimeout(resolve, {$milliseconds}));
        })()
    JS);
}

function welcomeMcpSelectedBenefit(mixed $page): ?string
{
    return $page->script('document.querySelector(\'[data-testid^="welcome-mcp-benefit-"][aria-pressed="true"]\')?.dataset.testid');
}

function welcomeMcpFocus(mixed $page, string $testId): void
{
    $page->script("document.querySelector('[data-testid=\"{$testId}\"]').focus(); true;");
}

/**
 * @return list<string>
 */
function welcomeMcpTestIds(mixed $page, string $selector): array
{
    $encodedSelector = json_encode($selector);

    return $page->script("[...document.querySelectorAll({$encodedSelector})].map((element) => element.dataset.testid)");
}

/**
 * @return list<string>
 */
function welcomeMcpLines(mixed $page, string $testId): array
{
    return $page->script("document.querySelector('[data-testid=\"{$testId}\"]').innerText.split('\\n')");
}

function welcomeMcpStyle(mixed $page, string $testId, string $property): string
{
    return $page->script("getComputedStyle(document.querySelector('[data-testid=\"{$testId}\"]')).{$property}");
}

/**
 * @return array{top: float|int, bottom: float|int, left: float|int, right: float|int, width: float|int, height: float|int}
 */
function welcomeMcpRect(mixed $page, string $testId): array
{
    return $page->script(<<<JS
        (() => {
            const rect = document.querySelector('[data-testid="{$testId}"]').getBoundingClientRect();
            return { top: rect.top, bottom: rect.bottom, left: rect.left, right: rect.right, width: rect.width, height: rect.height };
        })()
    JS);
}

/**
 * @return list<float|int>
 */
function welcomeMcpTops(mixed $page, string $selector): array
{
    $encodedSelector = json_encode($selector);

    return $page->script("[...document.querySelectorAll({$encodedSelector})].map((element) => element.getBoundingClientRect().top)");
}

/**
 * @return array{width: int, height: int, viewportWidth: int, viewportHeight: int}
 */
function welcomeMcpScrollSize(mixed $page): array
{
    return $page->script('({ width: document.documentElement.scrollWidth, height: document.documentElement.scrollHeight, viewportWidth: window.innerWidth, viewportHeight: window.innerHeight })');
}

function welcomeMcpScrollToBottom(mixed $page): void
{
    $page->script('window.scrollTo(0, document.documentElement.scrollHeight); true;');
}

function welcomeMcpActiveTestId(mixed $page): ?string
{
    return $page->script('document.activeElement.dataset.testid');
}

function welcomeMcpActiveStyle(mixed $page, string $property): string
{
    return $page->script("getComputedStyle(document.activeElement).{$property}");
}

/**
 * @return list<string>
 */
function welcomeMcpCopied(mixed $page): array
{
    return $page->script('window.__copied');
}

function welcomeMcpChatAvatar(mixed $page, string $benefit): string
{
    return $page->script(<<<JS
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
}

test('the MCP step opens a dialog with the steps and the app link, and can be skipped', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-connect-claude');

    $scrollSize = welcomeMcpScrollSize($page);

    expect($scrollSize['height'])->toBeLessThanOrEqual($scrollSize['viewportHeight']);

    expect(welcomeMcpTestIds($page, '[data-testid^=welcome-mcp-benefit-]:not([data-testid*=-icon-])'))->toBe(['welcome-mcp-benefit-ideas', 'welcome-mcp-benefit-schedule', 'welcome-mcp-benefit-queue', 'welcome-mcp-benefit-analytics']);

    $page->assertSee('Run your social media from a chat')
        ->assertMissing('@welcome-mcp-composer')
        ->assertVisible('@welcome-mcp-benefit-schedule')
        ->assertVisible('@welcome-mcp-benefit-ideas')
        ->click('@welcome-mcp-benefit-schedule');
    waitForWelcomeMcpChat($page, 'schedule');

    $page->assertSeeIn('@welcome-mcp-chat-schedule', 'Take the next idea on my board')
        ->assertSeeIn('@welcome-mcp-chat-schedule', 'schedule it for Tuesday at 10:00 AM');

    expect(welcomeMcpLines($page, 'welcome-mcp-chat-schedule'))->toContain('Scheduled')
        ->and(welcomeMcpTestIds($page, '[data-testid=welcome-mcp-chat-schedule] [data-testid^=welcome-mcp-schedule-post-]'))->toBe(['welcome-mcp-schedule-post-instagram', 'welcome-mcp-schedule-post-linkedin']);

    $page->click('@welcome-mcp-benefit-analytics');
    waitForWelcomeMcpChat($page, 'analytics');

    expect(welcomeMcpStyle($page, 'welcome-mcp-benefit-icon-analytics', 'backgroundColor'))->not->toBe(welcomeMcpStyle($page, 'welcome-mcp-benefit-icon-queue', 'backgroundColor'));

    $page->assertSeeIn('@welcome-mcp-chat-analytics', 'How was my month')
        ->assertSeeIn('@welcome-mcp-chat-analytics', 'Total Followers')
        ->click('@welcome-mcp-benefit-queue');
    waitForWelcomeMcpChat($page, 'queue');

    expect(welcomeMcpLines($page, 'welcome-mcp-chat-queue'))->toContain('Tomorrow');

    $page->assertSeeIn('@welcome-mcp-chat-queue', 'New')
        ->click('@welcome-mcp-benefit-ideas');
    waitForWelcomeMcpChat($page, 'ideas');

    expect(array_count_values(welcomeMcpLines($page, 'welcome-mcp-chat-ideas'))['New idea'] ?? 0)->toBe(2);

    $page->assertSeeIn('@welcome-mcp-chat-ideas', 'To Do')
        ->assertSeeIn('@welcome-mcp-chat-ideas', 'In Progress')
        ->assertAttributeMissing('@welcome-mcp-connect-claude', 'href');

    expect(collect(welcomeMcpTops($page, '[data-testid^=welcome-mcp-connect-]:not([data-testid=welcome-mcp-connect-ai])'))->map(fn (float|int $top): float => round($top))->unique()->count())->toBe(1);

    $page->assertSeeIn('@welcome-mcp-connect-claude', 'Connect with Claude')
        ->assertMissing('@welcome-mcp-copy')
        ->assertMissing('@mcp-dialog')
        ->assertMissing('@welcome-mcp-continue');

    stubWelcomeMcpConnectTab($page);
    $page->click('@welcome-mcp-connect-claude');
    waitForWelcomeMcpTestId($page, 'mcp-dialog-step-paste');

    $connectClickPath = parse_url(route('app.welcome.mcp.connect-click'), PHP_URL_PATH);

    expect(waitForWelcomeMcpRequest($page, $connectClickPath, 204))->toBeTrue()
        ->and(welcomeMcpCopied($page))->toBe([])
        ->and(welcomeMcpActiveTestId($page))->toBe('mcp-dialog')
        ->and(welcomeMcpActiveStyle($page, 'outlineStyle'))->toBe('none');

    $page->assertSeeIn('@mcp-dialog-name-copy', 'Copy')
        ->assertSeeIn('@mcp-dialog-url-copy', 'Copy')
        ->click('@mcp-dialog-name-copy');
    waitForWelcomeMcpCopied($page, 1);

    $page->assertSeeIn('@mcp-dialog-name-copy', 'Copied')
        ->assertDontSeeIn('@mcp-dialog-url-copy', 'Copied');

    $page->click('@mcp-dialog-url-copy');
    waitForWelcomeMcpCopied($page, 2);

    $page->assertSeeIn('@mcp-dialog', 'Connect TryPost to Claude')
        ->assertSeeIn('@mcp-dialog-url', route('mcp.trypost'))
        ->assertSeeIn('@mcp-dialog-step-paste', "Paste the URL into the connector's URL field")
        ->assertVisible('@mcp-dialog-step-add')
        ->assertVisible('@mcp-dialog-step-login')
        ->assertSeeIn('@mcp-dialog-waiting', 'Waiting for your AI to connect')
        ->assertCount('[data-sonner-toast]', 0)
        ->assertAttribute('@mcp-dialog-open', 'target', '_blank');

    expect(welcomeMcpCopied($page))->toBe(['TryPost', route('mcp.trypost')])
        ->and($page->attribute('@mcp-dialog-open', 'href'))->toStartWith('https://claude.ai/');

    $page->assertSeeIn('@mcp-dialog-open', 'Open Claude')
        ->click('@mcp-dialog-open')
        ->assertPresent('@mcp-dialog');

    $page->keys('@mcp-dialog', 'Escape');
    waitForWelcomeMcpTestIdGone($page, 'mcp-dialog');

    $page->click('@welcome-mcp-connect-chatgpt');
    waitForWelcomeMcpTestId($page, 'mcp-dialog-step-open');

    $page->assertSeeIn('@mcp-dialog', 'Connect TryPost to ChatGPT')
        ->assertSeeIn('@mcp-dialog-step-open', 'Open TryPost in ChatGPT')
        ->assertVisible('@mcp-dialog-step-login')
        ->assertMissing('@mcp-dialog-step-paste')
        ->assertMissing('@mcp-dialog-name')
        ->assertMissing('@mcp-dialog-url')
        ->assertAttribute('@mcp-dialog-open', 'href', 'https://chatgpt.com/plugins/plugin_asdk_app_6ad0fbec07f4819191139b8a00f5f129');

    $page->keys('@mcp-dialog', 'Escape');
    waitForWelcomeMcpTestIdGone($page, 'mcp-dialog');

    $page->assertSeeIn('@welcome-mcp-connect-other', 'Connect another app')
        ->click('@welcome-mcp-connect-other');
    waitForWelcomeMcpTestId($page, 'mcp-dialog-config');

    $page->assertSeeIn('@mcp-dialog', 'Connect TryPost to another app')
        ->assertVisible('@mcp-dialog-step-add')
        ->assertSeeIn('@mcp-dialog-config', route('mcp.trypost'))
        ->assertMissing('@mcp-dialog-open')
        ->keys('@mcp-dialog', 'Escape');
    waitForWelcomeMcpTestIdGone($page, 'mcp-dialog');

    $page->click('@welcome-mcp-skip');

    $schedulePath = parse_url(route('app.posts.index'), PHP_URL_PATH);
    waitForWelcomeMcpPath($page, $schedulePath);

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
        ->assertAttribute('@welcome-mcp-pill-ideas', 'aria-pressed', 'true');

    $scrollSize = welcomeMcpScrollSize($page);

    expect($scrollSize['width'])->toBeLessThanOrEqual($scrollSize['viewportWidth']);

    welcomeMcpScrollToBottom($page);
    welcomeMcpPause($page, 300);

    $skip = welcomeMcpRect($page, 'welcome-mcp-skip-link');
    $connect = welcomeMcpRect($page, 'welcome-mcp-connect-ai');
    $actions = welcomeMcpRect($page, 'welcome-actions');

    expect([
        'compact' => $actions['height'] < 120,
        'windowAboveFooter' => welcomeMcpRect($page, 'welcome-mcp-window')['bottom'] <= $actions['top'],
        'connectBeforeSkip' => round($skip['top']) === round($connect['top']) && $connect['left'] < $skip['left'],
    ])->toBe(['compact' => true, 'windowAboveFooter' => true, 'connectBeforeSkip' => true]);

    $page->assertMissing('@welcome-mcp-composer')
        ->assertSeeIn('@welcome-mcp-connect-ai', 'Connect your AI')
        ->assertVisible('@welcome-mcp-skip-link')
        ->assertMissing('@welcome-mcp-connect-claude')
        ->assertMissing('@welcome-mcp-connect-other')
        ->assertMissing('@welcome-mcp-skip');

    stubWelcomeMcpConnectTab($page);
    $page->click('@welcome-mcp-connect-ai');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-pick-other');

    $page->assertSeeIn('@welcome-mcp-ai-picker', 'Where do you want to use TryPost?')
        ->assertVisible('@welcome-mcp-pick-claude')
        ->assertVisible('@welcome-mcp-pick-chatgpt')
        ->click('@welcome-mcp-pick-other');
    waitForWelcomeMcpTestId($page, 'mcp-dialog-config');

    $page->assertSeeIn('@mcp-dialog', 'Connect TryPost to another app')
        ->assertMissing('@welcome-mcp-ai-picker')
        ->keys('@mcp-dialog', 'Escape');
    waitForWelcomeMcpTestIdGone($page, 'mcp-dialog');

    $page->click('@welcome-mcp-connect-ai');
    waitForWelcomeMcpTestId($page, 'welcome-mcp-pick-claude');

    $page->click('@welcome-mcp-pick-claude');
    waitForWelcomeMcpTestId($page, 'mcp-dialog-step-paste');
    welcomeMcpPause($page, 600);

    $dialog = welcomeMcpRect($page, 'mcp-dialog');

    expect(['bottom' => (int) round($dialog['bottom']), 'width' => (int) round($dialog['width'])])->toBe(['bottom' => 844, 'width' => 390]);

    $page->assertVisible('@mcp-dialog-open')
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

        expect(welcomeMcpChatAvatar($page, $benefit))->toBe($withPhoto ? 'photo' : 'initials');
    }

    expect(welcomeMcpLines($page, 'welcome-mcp-chat-queue'))->toContain('Tomorrow');

    $page->assertNoJavaScriptErrors();
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

    $page->assertSeeIn('@welcome-mcp-followers-tooltip', now()->format('M Y'))
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
    waitForWelcomeMcpTestId($page, 'mcp-dialog-waiting');

    mcpAccessToken($owner, mcpOauthClient('Claude'), $owner->currentWorkspace);

    expect(waitForWelcomeMcpCelebration($page))->toBe('celebrated');

    expect(trim($page->text('@welcome-mcp-announcement')))->toBe('Claude is connected');

    $page->assertSeeIn('@welcome-mcp-status', 'Claude is connected')
        ->assertVisible('@welcome-mcp-continue')
        ->assertMissing('@welcome-mcp-connect-claude')
        ->assertMissing('@welcome-mcp-skip');

    expect(welcomeMcpRect($page, 'welcome-mcp-continue')['top'])->toBeLessThan(welcomeMcpRect($page, 'welcome-mcp-status')['top']);

    $page->assertNoJavaScriptErrors();
});

test('the MCP examples rotate on their own until the user picks a tab', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-benefit-ideas');

    expect(waitForWelcomeMcpBenefitChange($page, 'welcome-mcp-benefit-ideas'))->toBe('welcome-mcp-benefit-schedule');

    $page->click('@welcome-mcp-benefit-queue')
        ->hover('@welcome-title');
    welcomeMcpPause($page, 4000);

    expect(welcomeMcpSelectedBenefit($page))->toBe('welcome-mcp-benefit-queue');

    $page->assertNoJavaScriptErrors();
});

test('an owner who already connected an AI sees the connected state without confetti', function () {
    config(['trypost.self_hosted' => false]);

    $owner = welcomeMcpSubscriber();
    mcpAccessToken($owner, mcpOauthClient('Claude'), $owner->currentWorkspace);
    $this->actingAs($owner);

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-status');
    welcomeMcpPause($page, 1500);

    $page->assertNotPresent('@confetti-canvas')
        ->assertSeeIn('@welcome-mcp-status', 'Claude is connected')
        ->assertVisible('@welcome-mcp-continue')
        ->assertMissing('@mcp-dialog')
        ->assertMissing('@welcome-mcp-connect-claude')
        ->assertNoJavaScriptErrors();
});

test('keyboard focus on the examples pauses the rotation', function () {
    config(['trypost.self_hosted' => false]);

    $this->actingAs(welcomeMcpSubscriber());

    $page = visit(route('app.welcome.mcp'))->resize(1440, 900);
    waitForWelcomeMcpTestId($page, 'welcome-mcp-benefit-ideas');

    welcomeMcpFocus($page, 'welcome-mcp-benefit-ideas');
    welcomeMcpPause($page, 4000);

    expect(welcomeMcpSelectedBenefit($page))->toBe('welcome-mcp-benefit-ideas');

    $page->assertNoJavaScriptErrors();
});
