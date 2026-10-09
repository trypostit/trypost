<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

function waitForDialogFullScreenTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector('[data-testid="{$testId}"]');
                if (element && element.getBoundingClientRect().height > 0
                    && element.getAnimations().every((animation) => animation.playState !== 'running')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function dialogFullScreenAdmin(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user->fresh();
}

function dialogFullScreenGeometry(mixed $page, string $testId, string $footerSlot): array
{
    return $page->script(<<<JS
        (() => {
            const dialog = document.querySelector('[data-testid="{$testId}"]');
            const box = dialog.getBoundingClientRect();
            const footer = dialog.querySelector('[data-slot="{$footerSlot}"]').getBoundingClientRect();
            const style = getComputedStyle(dialog);

            return {
                fillsViewport: Math.abs(box.top) <= 1 && Math.abs(box.left) <= 1
                    && Math.abs(box.width - window.innerWidth) <= 1 && Math.abs(box.height - window.innerHeight) <= 1,
                square: parseFloat(style.borderTopLeftRadius) === 0,
                footerVisible: footer.top >= 0 && footer.bottom <= window.innerHeight && footer.height > 0,
                footerAtBottom: window.innerHeight - footer.bottom <= 16,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        })()
    JS);
}

function openDialogFullScreenLabel(int $width, int $height): mixed
{
    $page = visit(route('app.labels.index'))->resize($width, $height);
    waitForDialogFullScreenTestId($page, 'labels-empty-create');
    $page->click('@labels-empty-create');
    waitForDialogFullScreenTestId($page, 'create-label-sheet');

    return $page;
}

test('on a phone a dialog fills the screen with the close button and the footer in view', function () {
    $this->actingAs(dialogFullScreenAdmin());

    $page = openDialogFullScreenLabel(390, 844);

    expect(dialogFullScreenGeometry($page, 'create-label-sheet', 'dialog-footer'))->toBe([
        'fillsViewport' => true,
        'square' => true,
        'footerVisible' => true,
        'footerAtBottom' => true,
        'overflow' => false,
    ]);

    $scrolled = $page->script(<<<'JS'
        (() => {
            const dialog = document.querySelector('[data-testid="create-label-sheet"]');
            const footer = dialog.querySelector('[data-slot="dialog-footer"]');
            const filler = document.createElement('div');
            filler.innerHTML = Array.from({ length: 80 }, (_, index) => `<p>Line ${index}</p>`).join('');
            footer.parentElement.insertBefore(filler, footer);
            dialog.scrollTop = 600;

            const visible = (element) => {
                const box = element.getBoundingClientRect();
                const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

                return box.top >= 0 && box.bottom <= window.innerHeight && Boolean(hit && element.contains(hit));
            };

            return {
                scrolls: dialog.scrollHeight > dialog.clientHeight && dialog.scrollTop > 0,
                header: visible(dialog.querySelector('[data-slot="dialog-title"]')),
                close: visible(dialog.querySelector('[data-testid="dialog-close"]')),
                cancel: visible(dialog.querySelector('[data-testid="cancel-create-label"]')),
                submit: visible(dialog.querySelector('[data-testid="submit-create-label"]')),
            };
        })()
    JS);

    expect($scrolled)->toBe([
        'scrolls' => true,
        'header' => true,
        'close' => true,
        'cancel' => true,
        'submit' => true,
    ]);

    $page->assertNoJavaScriptErrors();
});

test('on a phone an alert dialog fills the screen with its footer in view', function () {
    $this->actingAs(dialogFullScreenAdmin());

    $page = visit(route('app.workspace.channels'))->resize(390, 844);
    waitForDialogFullScreenTestId($page, 'channels-empty-connect');
    $page->click('@channels-empty-connect');
    waitForDialogFullScreenTestId($page, 'connect-channel-instagram');
    $page->click('@connect-channel-instagram');
    waitForDialogFullScreenTestId($page, 'instagram-connect-professional');
    $page->click('@dialog-close');
    waitForDialogFullScreenTestId($page, 'connect-channel-exit-confirm');

    expect(dialogFullScreenGeometry($page, 'connect-channel-exit-confirm', 'alert-dialog-footer'))->toBe([
        'fillsViewport' => true,
        'square' => true,
        'footerVisible' => true,
        'footerAtBottom' => true,
        'overflow' => false,
    ]);

    $page->assertNoJavaScriptErrors();
});

test('on a phone a delete confirmation stays a centered card instead of filling the screen', function () {
    $user = dialogFullScreenAdmin();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $user->current_workspace_id, 'name' => 'Phone label']);
    $this->actingAs($user);

    $page = visit(route('app.labels.index'))->resize(390, 844);
    waitForDialogFullScreenTestId($page, "label-menu-{$label->id}");
    $page->click("@label-menu-{$label->id}");
    waitForDialogFullScreenTestId($page, "delete-label-{$label->id}");
    $page->click("@delete-label-{$label->id}");
    waitForDialogFullScreenTestId($page, 'confirm-delete-modal');

    $geometry = $page->script(<<<'JS'
        (() => {
            const box = document.querySelector('[data-testid="confirm-delete-modal"]').getBoundingClientRect();

            return {
                compact: box.height < window.innerHeight / 2,
                inset: box.left > 0 && box.right < window.innerWidth,
                centered: Math.abs((box.top + box.bottom) / 2 - window.innerHeight / 2) <= 2,
            };
        })()
    JS);

    expect($geometry)->toBe(['compact' => true, 'inset' => true, 'centered' => true]);
    $page->assertNoJavaScriptErrors();
});
