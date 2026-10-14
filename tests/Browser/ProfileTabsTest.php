<?php

declare(strict_types=1);

use App\Enums\User\Theme;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function waitForProfileTabsTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const selector = '[data-testid="{$testId}"]';
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector(selector);
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('the profile tabs keep password, login and sessions apart and remember the open tab in the url', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit'));
    waitForProfileTabsTestId($page, 'profile-tab-login');

    $page->assertVisible('[data-test="update-profile-button"]')
        ->assertMissing('@profile-new-password')
        ->click('@profile-tab-login');
    waitForProfileTabsTestId($page, 'profile-new-password');

    $page->assertVisible('[data-test="connected-account-google"]')
        ->assertQueryStringHas('tab', 'login')
        ->click('@profile-tab-sessions');
    waitForProfileTabsTestId($page, 'profile-sessions');

    $page->assertQueryStringHas('tab', 'sessions')
        ->assertMissing('@profile-new-password')
        ->assertNoJavaScriptErrors();
});

test('a link to the login tab opens it', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit', ['tab' => 'login']));
    waitForProfileTabsTestId($page, 'profile-new-password');

    $page->assertVisible('@profile-new-password')
        ->assertMissing('[data-test="update-profile-button"]')
        ->assertNoJavaScriptErrors();
});

test('the delete account button sits under a neutral warning', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit'));
    waitForProfileTabsTestId($page, 'delete-account-warning');

    expect($page->script(<<<'JS'
        (() => {
            const warning = document.querySelector('[data-testid="delete-account-warning"]').getBoundingClientRect();
            const button = document.querySelector('[data-test="delete-user-button"]').getBoundingClientRect();
            const warningColor = getComputedStyle(document.querySelector('[data-testid="delete-account-warning"]')).backgroundColor;
            const buttonColor = getComputedStyle(document.querySelector('[data-test="delete-user-button"]')).backgroundColor;
            return button.top >= warning.bottom && Math.round(button.left) === Math.round(warning.left) && warningColor !== buttonColor;
        })()
    JS))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('each session shows its browser and system icons under the log out button', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    DB::table(config('session.table', 'sessions'))->insert([
        'id' => 'firefox-on-windows',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0',
        'payload' => base64_encode(json_encode(['_previous' => ['url' => 'https://app.trypost.test/insights?range=30d', 'route' => 'app.insights']])),
        'last_activity' => now()->subHour()->timestamp,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.profile.edit', ['tab' => 'sessions']));
    waitForProfileTabsTestId($page, 'session-browser-icon-firefox-on-windows');

    $page->assertAttribute('@session-browser-icon-firefox-on-windows', 'src', '/images/browsers/firefox.svg')
        ->assertAttribute('@session-os-icon-firefox-on-windows', 'src', '/images/os/windows.png')
        ->assertAttribute('@session-device-icon-firefox-on-windows', 'src', '/images/devices/desktop.png')
        ->assertSeeIn('[data-test="session-row"]:has([data-testid="session-browser-icon-firefox-on-windows"])', 'Firefox 130.0')
        ->assertSeeIn('@session-last-page-firefox-on-windows', '/insights');

    expect($page->script(<<<'JS'
        (() => {
            const button = document.querySelector('[data-test="log-out-other-sessions-button"]').getBoundingClientRect();
            const firstRow = document.querySelector('[data-test="session-row"]').getBoundingClientRect();
            return button.bottom <= firstRow.top;
        })()
    JS))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the apple logo of a mac session turns light on the dark theme', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['theme' => Theme::Dark]);
    DB::table(config('session.table', 'sessions'))->insert([
        'id' => 'safari-on-mac',
        'user_id' => $user->id,
        'ip_address' => '203.0.113.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
        'payload' => '',
        'last_activity' => now()->subHour()->timestamp,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.profile.edit', ['tab' => 'sessions']));
    waitForProfileTabsTestId($page, 'session-os-icon-safari-on-mac');

    expect($page->script('getComputedStyle(document.querySelector(\'[data-testid="session-os-icon-safari-on-mac"]\')).filter'))->toBe('invert(1)');
    $page->assertNoJavaScriptErrors();
});

test('the github mark turns light on the dark theme while google keeps its colors', function () {
    $this->actingAs(User::factory()->create(['theme' => Theme::Dark]));

    $page = visit(route('app.profile.edit', ['tab' => 'login']));
    waitForProfileTabsTestId($page, 'connected-account-icon-github');

    expect($page->script('getComputedStyle(document.querySelector(\'[data-testid="connected-account-icon-github"]\')).filter'))->toBe('invert(1)')
        ->and($page->script('getComputedStyle(document.querySelector(\'[data-testid="connected-account-icon-google"]\')).filter'))->toBe('none');
    $page->assertNoJavaScriptErrors();
});

test('logging out other devices asks for a plain confirmation without a password', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $table = config('session.table', 'sessions');
    DB::table($table)->insert([
        ['id' => 'phone', 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'payload' => '', 'last_activity' => now()->subHour()->timestamp],
        ['id' => 'laptop', 'user_id' => $user->id, 'ip_address' => '203.0.113.10', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:130.0) Gecko/20100101 Firefox/130.0', 'payload' => '', 'last_activity' => now()->subDay()->timestamp],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.profile.edit', ['tab' => 'sessions']));
    waitForProfileTabsTestId($page, 'session-browser-icon-phone');
    $page->click('[data-test="log-out-other-sessions-button"]');
    waitForProfileTabsTestId($page, 'confirm-log-out-other-sessions');

    expect($page->script('document.querySelectorAll(\'[data-testid="confirm-delete-modal"] input\').length'))->toBe(0);

    $page->click('@confirm-log-out-other-sessions');
    for ($attempt = 0; $attempt < 50 && DB::table($table)->whereIn('id', ['phone', 'laptop'])->exists(); $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }

    expect(DB::table($table)->whereIn('id', ['phone', 'laptop'])->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});
