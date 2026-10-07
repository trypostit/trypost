<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

function waitForDialogFooterTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                const element = document.querySelector('[data-testid="{$testId}"]');
                if (element && element.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('a dialog footer sits in a rounded muted panel inset from the dialog edges', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user->fresh());

    $page = visit(route('app.labels.index'))->resize(1280, 900);
    waitForDialogFooterTestId($page, 'labels-empty-create');
    $page->click('@labels-empty-create');
    waitForDialogFooterTestId($page, 'create-label-sheet');
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    $footer = $page->script(<<<'JS'
        (() => {
            const dialog = document.querySelector('[data-testid="create-label-sheet"]').getBoundingClientRect();
            const footer = document.querySelector('[data-testid="create-label-sheet"] [data-slot="dialog-footer"]');
            const box = footer.getBoundingClientRect();
            const probe = document.createElement('div');
            probe.className = 'bg-muted';
            document.body.appendChild(probe);
            const muted = getComputedStyle(probe).backgroundColor;
            probe.remove();
            return {
                muted: getComputedStyle(footer).backgroundColor === muted,
                rounded: parseFloat(getComputedStyle(footer).borderTopLeftRadius) > 0,
                insetStart: Math.round(box.left - dialog.left),
                insetEnd: Math.round(dialog.right - box.right),
                insetBottom: Math.round(dialog.bottom - box.bottom),
                buttonTop: Math.round(footer.querySelector('[data-testid="submit-create-label"]').getBoundingClientRect().top - box.top),
                buttonBottom: Math.round(box.bottom - footer.querySelector('[data-testid="submit-create-label"]').getBoundingClientRect().bottom),
                buttonEnd: Math.round(box.right - footer.querySelector('[data-testid="submit-create-label"]').getBoundingClientRect().right),
            };
        })()
    JS);

    expect($footer)->toMatchArray([
        'muted' => true,
        'rounded' => true,
        'insetStart' => 8,
        'insetEnd' => 8,
        'insetBottom' => 8,
        'buttonTop' => 12,
        'buttonBottom' => 12,
        'buttonEnd' => 12,
    ]);

    $page->hover('@cancel-create-label');
    $page->script('new Promise((resolve) => setTimeout(resolve, 300))');

    expect($page->script(<<<'JS'
        (() => {
            const probe = document.createElement('div');
            probe.className = 'bg-secondary';
            document.body.appendChild(probe);
            const secondary = getComputedStyle(probe).backgroundColor;
            probe.remove();
            return getComputedStyle(document.querySelector('[data-testid="cancel-create-label"]')).backgroundColor === secondary;
        })()
    JS))->toBeTrue();

    $geometry = $page->script(<<<'JS'
        (() => {
            const box = document.querySelector('[data-testid="create-label-sheet"]').getBoundingClientRect();
            const close = document.querySelector('[data-testid="create-label-sheet"] [data-testid="dialog-close"]').getBoundingClientRect();

            return {
                centeredX: Math.abs((box.left + box.right) / 2 - window.innerWidth / 2) <= 1,
                centeredY: Math.abs((box.top + box.bottom) / 2 - window.innerHeight / 2) <= 1,
                maxWidth: Math.round(box.width) === 512,
                shorterThanViewport: box.height < window.innerHeight - 100,
                closeInside: close.top >= box.top && close.right <= box.right,
            };
        })()
    JS);

    expect($geometry)->toBe([
        'centeredX' => true,
        'centeredY' => true,
        'maxWidth' => true,
        'shorterThanViewport' => true,
        'closeInside' => true,
    ]);

    $page->resize(390, 844);
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    $layout = $page->script(<<<'JS'
        (() => {
            const footer = document.querySelector('[data-testid="create-label-sheet"] [data-slot="dialog-footer"]');
            const cancel = footer.querySelector('[data-testid="cancel-create-label"]').getBoundingClientRect();
            const submit = footer.querySelector('[data-testid="submit-create-label"]').getBoundingClientRect();
            return {
                sameRow: Math.round(cancel.top) === Math.round(submit.top),
                fillsRest: submit.width > cancel.width && Math.abs(footer.getBoundingClientRect().right - 12 - submit.right) <= 1 && Math.abs(submit.left - cancel.right - 8) <= 1,
                primaryLast: submit.left > cancel.left,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            };
        })()
    JS);

    expect($layout)->toMatchArray([
        'sameRow' => true,
        'fillsRest' => true,
        'primaryLast' => true,
        'overflow' => false,
    ]);

    $page->assertNoJavaScriptErrors();
});
