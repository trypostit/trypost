<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

function waitForConnectDialogTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function connectDialogAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('the connect button opens the network grid', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-linkedin')
        ->assertVisible('@connect-channel-instagram')
        ->assertNoJavaScriptErrors();
});

test('instagram shows its method step inside the same dialog and can go back', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-instagram');
    $page->click('@connect-channel-instagram');
    waitForConnectDialogTestId($page, 'instagram-connect-standalone');

    $page->assertVisible('@instagram-connect-standalone')
        ->assertMissing('@connect-channel-linkedin');

    $page->click('@connect-channel-back');
    waitForConnectDialogTestId($page, 'connect-channel-linkedin');

    $page->assertVisible('@connect-channel-linkedin')->assertNoJavaScriptErrors();
});

test('an oauth network opens the popup and closes the dialog', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->script('window.__opened = []; window.open = (url) => { if (url) { window.__opened.push(url); } return { closed: false, focus() {} }; };');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-linkedin');
    $page->click('@connect-channel-linkedin');

    $linkedinPath = parse_url(route('app.social.linkedin.connect'), PHP_URL_PATH);
    expect($page->script('window.__opened'))->toBe([$linkedinPath]);
    $page->assertMissing('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('the connect dialog is a fixed 840 by 700 frame with the grid scrolling inside', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'channel-platform-grid');

    $frame = $page->script(<<<'JS'
        (() => {
            const dialog = document.querySelector('[data-testid="connect-channel-dialog"]');
            const style = getComputedStyle(dialog);
            const scroller = document.querySelector('[data-testid="channel-platform-grid"]').parentElement;

            return {
                width: style.width,
                height: style.height,
                overflowY: getComputedStyle(scroller).overflowY,
                scrolls: scroller.scrollHeight > scroller.clientHeight,
            };
        })();
    JS);

    expect($frame)->toBe([
        'width' => '840px',
        'height' => '700px',
        'overflowY' => 'auto',
        'scrolls' => true,
    ]);
    $page->assertNoJavaScriptErrors();
});

test('the info button opens the network details and back returns to the grid', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-bluesky');
    $page->hover('@connect-channel-bluesky');
    $page->click('@connect-info-bluesky');
    waitForConnectDialogTestId($page, 'connect-details');

    $page->assertVisible('@connect-details')
        ->assertVisible('@connect-details-help')
        ->assertSeeIn('@connect-details-connect', 'Connect Bluesky')
        ->assertMissing('@channel-platform-grid');

    $page->click('@connect-details-back');
    waitForConnectDialogTestId($page, 'connect-channel-bluesky');

    $page->assertVisible('@channel-platform-grid')
        ->assertMissing('@connect-details')
        ->assertNoJavaScriptErrors();
});

test('connecting from the details view starts the same flow as the card', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->script('window.__opened = []; window.open = (url) => { if (url) { window.__opened.push(url); } return { closed: false, focus() {} }; };');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-linkedin');
    $page->hover('@connect-channel-linkedin');
    $page->click('@connect-info-linkedin');
    waitForConnectDialogTestId($page, 'connect-details-connect');
    $page->click('@connect-details-connect');

    $linkedinPath = parse_url(route('app.social.linkedin.connect'), PHP_URL_PATH);
    expect($page->script('window.__opened'))->toBe([$linkedinPath]);
    $page->assertMissing('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('instagram details connect opens the method step in the dialog', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-instagram');
    $page->hover('@connect-channel-instagram');
    $page->click('@connect-info-instagram');
    waitForConnectDialogTestId($page, 'connect-details-connect');
    $page->click('@connect-details-connect');
    waitForConnectDialogTestId($page, 'instagram-connect-standalone');

    $page->assertVisible('@instagram-connect-standalone')
        ->assertMissing('@connect-details')
        ->assertNoJavaScriptErrors();
});

function openInstagramConnectStep(): mixed
{
    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->script('window.__opened = []; window.open = (url) => { if (url) { window.__opened.push(url); } return { closed: false, focus() {} }; };');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-instagram');
    $page->click('@connect-channel-instagram');
    waitForConnectDialogTestId($page, 'instagram-connect-professional');

    return $page;
}

test('the instagram step offers the professional card and the facebook link only', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openInstagramConnectStep();

    $page->assertVisible('@connect-channel-logos')
        ->assertAttribute('[data-testid="connect-channel-logo"] img', 'alt', 'Instagram')
        ->assertSee(trans('accounts.instagram_connect.title'))
        ->assertSeeIn('@instagram-connect-professional', trans('accounts.instagram_connect.professional_types'))
        ->assertSeeIn('@instagram-connect-professional', trans('accounts.instagram_connect.features.automatic.description'))
        ->assertSeeIn('@instagram-connect-professional', trans('accounts.instagram_connect.features.metrics.description'))
        ->assertSeeIn('@instagram-connect-standalone', trans('accounts.instagram_connect.connect'))
        ->assertSeeIn('@instagram-connect-facebook', trans('accounts.instagram_connect.facebook_link'))
        ->assertNoJavaScriptErrors();

    $dialogText = $page->script('document.querySelector(\'[data-testid="connect-channel-dialog"]\').textContent');

    expect($dialogText)->not->toContain('Personal')->not->toContain('Notification');
});

test('connect to instagram starts the instagram login popup', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openInstagramConnectStep();
    $page->click('@instagram-connect-standalone');

    $instagramPath = parse_url(route('app.social.instagram.connect'), PHP_URL_PATH);
    expect($page->script('window.__opened'))->toBe([$instagramPath]);
    $page->assertMissing('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('the facebook link opens the requirements step without starting oauth and back returns', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openInstagramConnectStep();
    $page->click('@instagram-connect-facebook');
    waitForConnectDialogTestId($page, 'instagram-facebook-requirements');

    expect($page->script('window.__opened'))->toBe([]);
    $page->assertVisible('@instagram-facebook-requirements')
        ->assertSee(trans('accounts.instagram_facebook_requirements.title'))
        ->assertMissing('@instagram-connect-professional');

    $page->click('@connect-channel-back');
    waitForConnectDialogTestId($page, 'instagram-connect-professional');

    $page->assertVisible('@instagram-connect-professional')
        ->assertMissing('@instagram-facebook-requirements')
        ->assertNoJavaScriptErrors();
});

test('connect through facebook starts the instagram through facebook popup', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openInstagramConnectStep();
    $page->click('@instagram-connect-facebook');
    waitForConnectDialogTestId($page, 'instagram-facebook-requirements-connect');

    $learnHow = $page->script(<<<'JS'
        (() => {
            const link = document.querySelector('[data-testid="instagram-facebook-requirements-learn-how"]');

            return { host: new URL(link.href).host, target: link.target };
        })();
    JS);

    expect($learnHow)->toBe(['host' => 'www.facebook.com', 'target' => '_blank']);

    $page->click('@instagram-facebook-requirements-connect');

    $facebookPath = parse_url(route('app.social.instagram-facebook.connect'), PHP_URL_PATH);
    expect($page->script('window.__opened'))->toBe([$facebookPath]);
    $page->assertMissing('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('the instagram help menu links to the instagram help center in a new tab', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openInstagramConnectStep();
    $page->click('@instagram-connect-help');
    waitForConnectDialogTestId($page, 'instagram-connect-help-convert');

    $links = $page->script(<<<'JS'
        (() => ['account-type', 'convert'].map((key) => {
            const link = document.querySelector(`[data-testid="instagram-connect-help-${key}"]`);

            return { host: new URL(link.href).host, target: link.target, text: link.textContent.trim() };
        }))();
    JS);

    expect($links)->toBe([
        ['host' => 'help.instagram.com', 'target' => '_blank', 'text' => trans('accounts.instagram_connect.help.account_type')],
        ['host' => 'help.instagram.com', 'target' => '_blank', 'text' => trans('accounts.instagram_connect.help.convert')],
    ]);
    $page->assertNoJavaScriptErrors();
});

test('closing a later step asks for confirmation and continue keeps the step', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openInstagramConnectStep();
    $page->click('@dialog-close');
    waitForConnectDialogTestId($page, 'connect-channel-exit-confirm');

    $page->assertSeeIn('@connect-channel-exit-confirm', trans('channels.dialog.exit_confirm.title', ['network' => 'Instagram']));

    $page->click('@connect-channel-exit-continue');
    waitForConnectDialogTestId($page, 'instagram-connect-professional');

    $page->assertMissing('@connect-channel-exit-confirm')
        ->assertVisible('@instagram-connect-professional')
        ->assertNoJavaScriptErrors();
});

test('exiting from the confirmation closes the connect dialog', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openInstagramConnectStep();
    $page->click('@dialog-close');
    waitForConnectDialogTestId($page, 'connect-channel-exit-leave');
    $page->click('@connect-channel-exit-leave');

    $page->assertMissing('@connect-channel-exit-confirm')
        ->assertMissing('@connect-channel-dialog')
        ->assertNoJavaScriptErrors();
});

function openTelegramConnectStep(): mixed
{
    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->script('window.__copied = []; navigator.clipboard.writeText = async (text) => { window.__copied.push(text); }; true;');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-telegram');
    $page->click('@connect-channel-telegram');
    waitForConnectDialogTestId($page, 'telegram-connect-command');

    return $page;
}

test('the telegram step shows both logos, the steps and the waiting status', function () {
    config()->set('trypost.platforms.telegram.bot_username', 'TryPost_Bot');
    $this->actingAs(connectDialogAdmin());

    $page = openTelegramConnectStep();

    $page->assertVisible('@connect-channel-logos')
        ->assertAttribute('[data-testid="connect-channel-logo"] img', 'alt', 'Telegram')
        ->assertSee(trans('accounts.telegram.title'))
        ->assertSee(trans('accounts.telegram.description'))
        ->assertSeeIn('@telegram-connect-steps', trans('accounts.telegram.steps'))
        ->assertSeeIn('@telegram-connect-bot', '@TryPost_Bot')
        ->assertSeeIn('@telegram-connect-waiting', trans('accounts.telegram.waiting'))
        ->assertVisible('@telegram-connect-regenerate')
        ->assertNoJavaScriptErrors();

    $command = $page->script('document.querySelector(\'[data-testid="telegram-connect-command"]\').textContent.trim()');
    expect($command)->toStartWith('/connect ');

    $openBot = $page->script(<<<'JS'
        (() => {
            const link = document.querySelector('[data-testid="telegram-connect-open-bot"]');

            return { href: link.href, target: link.target };
        })();
    JS);

    expect($openBot)->toBe(['href' => 'https://t.me/TryPost_Bot', 'target' => '_blank']);

    $live = $page->script('document.querySelector(\'[data-testid="telegram-connect-waiting"]\').parentElement.getAttribute("aria-live")');
    expect($live)->toBe('polite');
});

test('copying the telegram command shows an inline copied state without a toast', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openTelegramConnectStep();
    $command = $page->script('document.querySelector(\'[data-testid="telegram-connect-command"]\').textContent.trim()');

    $page->click('@telegram-connect-copy');
    waitForConnectDialogTestId($page, 'telegram-connect-copied');

    $page->assertSeeIn('@telegram-connect-copied', trans('common.actions.copied'))
        ->assertMissing('@telegram-connect-copy')
        ->assertNoJavaScriptErrors();

    expect($page->script('window.__copied'))->toBe([$command])
        ->and($page->script('document.querySelectorAll("[data-sonner-toast]").length'))->toBe(0);
});

test('generating a new telegram command replaces the previous one', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openTelegramConnectStep();
    $first = $page->script('document.querySelector(\'[data-testid="telegram-connect-command"]\').textContent.trim()');

    $page->click('@telegram-connect-regenerate');
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector('[data-testid="telegram-connect-command"]');
                if (el && el.textContent.trim() !== '{$first}') return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);

    $second = $page->script('document.querySelector(\'[data-testid="telegram-connect-command"]\').textContent.trim()');

    expect($second)->toStartWith('/connect ')->not->toBe($first);
    $page->assertVisible('@telegram-connect-waiting')->assertNoJavaScriptErrors();
});

test('the telegram help menu links to the official telegram faq in a new tab', function () {
    $this->actingAs(connectDialogAdmin());

    $page = openTelegramConnectStep();
    $page->click('@telegram-connect-help');
    waitForConnectDialogTestId($page, 'telegram-connect-help-bot-privacy');

    $links = $page->script(<<<'JS'
        (() => ['channel-admins', 'group-admins', 'bot-privacy'].map((key) => {
            const link = document.querySelector(`[data-testid="telegram-connect-help-${key}"]`);

            return { host: new URL(link.href).host, target: link.target, text: link.textContent.trim() };
        }))();
    JS);

    expect($links)->toBe([
        ['host' => 'telegram.org', 'target' => '_blank', 'text' => trans('accounts.telegram.help.channel_admins')],
        ['host' => 'telegram.org', 'target' => '_blank', 'text' => trans('accounts.telegram.help.group_admins')],
        ['host' => 'telegram.org', 'target' => '_blank', 'text' => trans('accounts.telegram.help.bot_privacy')],
    ]);
    $page->assertNoJavaScriptErrors();
});

test('escape on the telegram step asks for confirmation too', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'connect-channel-telegram');
    $page->click('@connect-channel-telegram');
    waitForConnectDialogTestId($page, 'connect-channel-back');

    $page->keys('@connect-channel-back', 'Escape');
    waitForConnectDialogTestId($page, 'connect-channel-exit-confirm');

    $page->assertSeeIn('@connect-channel-exit-confirm', trans('channels.dialog.exit_confirm.title', ['network' => 'Telegram']));

    $page->keys('@connect-channel-exit-continue', 'Escape');
    waitForConnectDialogTestId($page, 'connect-channel-back');

    $page->assertMissing('@connect-channel-exit-confirm')
        ->assertVisible('@connect-channel-dialog')
        ->assertVisible('@connect-channel-back');

    $page->keys('@connect-channel-back', 'Escape');
    waitForConnectDialogTestId($page, 'connect-channel-exit-leave');
    $page->click('@connect-channel-exit-leave');

    $page->assertMissing('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('closing the network list does not ask for confirmation', function () {
    $this->actingAs(connectDialogAdmin());

    $page = visit(route('app.workspace.channels'));
    waitForConnectDialogTestId($page, 'channels-connect');
    $page->click('@channels-connect');
    waitForConnectDialogTestId($page, 'channel-platform-grid');
    $page->click('@dialog-close');

    $page->assertMissing('@connect-channel-dialog')
        ->assertMissing('@connect-channel-exit-confirm')
        ->assertNoJavaScriptErrors();
});
