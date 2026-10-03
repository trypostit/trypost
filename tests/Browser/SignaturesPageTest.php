<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceSignature;

function waitForSignaturesPageTestId(mixed $page, string $testId): void
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

function signaturesPageOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    return [$user, $workspace];
}

test('the signatures page without signatures shows only the centered illustration and a create button', function () {
    [$user] = signaturesPageOwner();
    $this->actingAs($user);

    $page = visit(route('app.signatures.index'));
    waitForSignaturesPageTestId($page, 'signatures-empty');

    $page->assertVisible('@signatures-empty-illustration')
        ->assertMissing('@header-title')
        ->assertMissing('@create-signature-button')
        ->assertMissing('@header-search-input')
        ->assertSeeIn('@signatures-empty', __('signatures.empty_title'));

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]').getBoundingClientRect();
            const empty = document.querySelector('[data-testid="signatures-empty"]').getBoundingClientRect();
            return Math.abs((empty.top + empty.height / 2) - (scroller.top + scroller.height / 2)) <= 24
                && Math.abs((empty.left + empty.width / 2) - (scroller.left + scroller.width / 2)) <= 24;
        })()
    JS))->toBeTrue();

    $page->click('@signatures-empty-create');
    waitForSignaturesPageTestId($page, 'create-signature-sheet');

    $page->assertVisible('@create-signature-sheet')->assertNoJavaScriptErrors();
});

test('a signature search without results keeps the header and search and shows the illustration', function () {
    [$user, $workspace] = signaturesPageOwner();
    WorkspaceSignature::factory()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.signatures.index', ['search' => 'nothing-matches']));
    waitForSignaturesPageTestId($page, 'empty-state');

    $page->assertVisible('@header-title')
        ->assertVisible('@create-signature-button')
        ->assertVisible('@header-search-input')
        ->assertVisible('@signatures-empty-illustration')
        ->assertSeeIn('@empty-state', __('signatures.no_search_results'))
        ->assertMissing('@signatures-empty')
        ->assertMissing('@settings-centered')
        ->assertNoJavaScriptErrors();
});

test('clearing a signature search never flashes the no signatures state', function () {
    [$user, $workspace] = signaturesPageOwner();
    $signature = WorkspaceSignature::factory()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.signatures.index', ['search' => 'nothing-matches']));
    waitForSignaturesPageTestId($page, 'header-search-input');

    $flashed = $page->script(<<<'JS'
        (async () => {
            let flashed = false;
            const observer = new MutationObserver(() => {
                if (document.querySelector('[data-testid="signatures-empty"]')) flashed = true;
            });
            observer.observe(document.body, { childList: true, subtree: true });
            const input = document.querySelector('[data-testid="header-search-input"]');
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            for (let i = 0; i < 60 && !document.querySelector('[data-testid^="signature-row-"]'); i++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            observer.disconnect();
            return flashed;
        })()
    JS);

    expect($flashed)->toBeFalse();
    $page->assertVisible("@signature-row-{$signature->id}")->assertNoJavaScriptErrors();
});

test('a long signatures list starts at the top and scrolls normally', function () {
    [$user, $workspace] = signaturesPageOwner();
    WorkspaceSignature::factory()->count(30)->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.signatures.index'));
    waitForSignaturesPageTestId($page, 'header-title');

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]');
            const top = document.querySelector('[data-testid="header-title"]').getBoundingClientRect().top - scroller.getBoundingClientRect().top;
            const scrolls = scroller.scrollHeight > scroller.clientHeight;
            scroller.scrollTop = scroller.scrollHeight;
            return [document.querySelector('[data-testid="settings-centered"]') === null, top < 120, scrolls, scroller.scrollTop > 0];
        })()
    JS))->toBe([true, true, true, true]);

    $page->assertNoJavaScriptErrors();
});

test('the signatures empty state is never cut off on a short screen', function () {
    [$user] = signaturesPageOwner();
    $this->actingAs($user);

    $page = visit(route('app.signatures.index'));
    waitForSignaturesPageTestId($page, 'signatures-empty');
    $page->resize(375, 480);

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]');
            scroller.scrollTop = 0;
            const illustration = document.querySelector('[data-testid="signatures-empty-illustration"]').getBoundingClientRect();
            const topVisible = illustration.top >= scroller.getBoundingClientRect().top;
            scroller.scrollTop = scroller.scrollHeight;
            const button = document.querySelector('[data-testid="signatures-empty-create"]').getBoundingClientRect();
            return [topVisible, button.bottom <= scroller.getBoundingClientRect().bottom];
        })()
    JS))->toBe([true, true]);

    $page->assertNoJavaScriptErrors();
});

test('the signature dialog uses the standard dialog buttons and copy', function () {
    [$user] = signaturesPageOwner();
    $this->actingAs($user);

    $page = visit(route('app.signatures.index'));
    waitForSignaturesPageTestId($page, 'signatures-empty-create');
    $page->click('@signatures-empty-create');
    waitForSignaturesPageTestId($page, 'create-signature-sheet');

    $page->assertSeeIn('@create-signature-sheet', __('signatures.create.description'));

    [$cancel, $submit] = $page->script(<<<'JS'
        (() => ['cancel-create-signature', 'submit-create-signature'].map((id) =>
            Math.round(document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect().height)
        ))()
    JS);

    expect($cancel)->toBe($submit)->toBeLessThan(36);

    $page->assertNoJavaScriptErrors();
});

test('a signature row previews its text and keeps its actions in one menu', function () {
    [$user, $workspace] = signaturesPageOwner();
    $signature = WorkspaceSignature::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Launch',
        'content' => '#launch #trypost',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.signatures.index'));
    waitForSignaturesPageTestId($page, "signature-row-{$signature->id}");

    $page->assertSeeIn("@signature-preview-{$signature->id}", '#launch #trypost')
        ->click("@signature-menu-{$signature->id}");
    waitForSignaturesPageTestId($page, "edit-signature-{$signature->id}");

    $page->assertVisible("@delete-signature-{$signature->id}")
        ->click("@edit-signature-{$signature->id}");
    waitForSignaturesPageTestId($page, 'edit-signature-sheet');

    $page->assertVisible('@edit-signature-sheet')->assertNoJavaScriptErrors();
});
