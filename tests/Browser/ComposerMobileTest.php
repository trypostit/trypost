<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: Workspace}
 */
function composerMobileWorkspace(): array
{
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user, $workspace];
}

function waitForComposerMobileCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForComposerMobileTestId(mixed $page, string $testId): void
{
    waitForComposerMobileCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

function waitForComposerMobileSettled(mixed $page, string $testId): void
{
    waitForComposerMobileCondition($page, "(() => {
        const element = document.querySelector('[data-testid=\"{$testId}\"]');
        return element?.getAttribute('data-state') === 'open'
            && element.getAnimations().every((animation) => animation.playState !== 'running');
    })()");
}

function waitForComposerMobileGone(mixed $page, string $testId): void
{
    waitForComposerMobileCondition($page, "!document.querySelector('[data-testid=\"{$testId}\"]')");
}

function openComposerMobile(mixed $test, User $user, int $width, int $height): mixed
{
    $test->actingAs($user);
    $page = visit(route('app.posts.create'))->resize($width, $height);
    waitForComposerMobileSettled($page, 'post-composer-dialog');
    waitForComposerMobileTestId($page, 'composer-add-account');

    return $page;
}

function openComposerMobileNetworkCard(mixed $page, SocialAccount $account): void
{
    waitForComposerMobileCondition($page, "document.querySelector('[data-testid=\"composer-caption-{$account->id}\"]') || document.querySelector('[data-testid=\"composer-expand-{$account->id}\"]')");
    if (! $page->script("!!document.querySelector('[data-testid=\"composer-caption-{$account->id}\"]')")) {
        $page->click("@composer-expand-{$account->id}");
    }
    waitForComposerMobileTestId($page, "composer-caption-{$account->id}");
}

function selectComposerMobileChannel(mixed $page, SocialAccount $account): void
{
    $page->click('@composer-add-account');
    waitForComposerMobileTestId($page, "composer-account-option-{$account->id}");
    $page->click("@composer-account-option-{$account->id}")->click('@composer-add-account');
    waitForComposerMobileGone($page, "composer-account-option-{$account->id}");
    waitForComposerMobileTestId($page, "composer-account-{$account->id}");
}

function selectAllComposerMobileChannels(mixed $page): void
{
    $page->click('@composer-add-account');
    waitForComposerMobileTestId($page, 'composer-select-all');
    $page->click('@composer-select-all')->click('@composer-add-account');
    waitForComposerMobileGone($page, 'composer-select-all');
}

/**
 * @return array<string, mixed>
 */
function composerMobileRect(mixed $page, string $testId): array
{
    return (array) $page->script(<<<JS
        (() => {
            const rect = document.querySelector('[data-testid="{$testId}"]').getBoundingClientRect();
            return { top: rect.top, bottom: rect.bottom, left: rect.left, right: rect.right, height: rect.height, width: rect.width, viewportWidth: window.innerWidth, viewportHeight: window.innerHeight };
        })()
    JS);
}

function composerMobileTooltipCount(mixed $page): int
{
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');

    return (int) $page->script('document.querySelectorAll(\'[data-slot="tooltip-content"]\').length');
}

test('at 768px the header shows icon-only panel buttons, the editor keeps a usable width and network cards do not overflow', function () {
    [$user, $workspace] = composerMobileWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id]);
    SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]);

    WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);

    $page = openComposerMobile($this, $user, 768, 1024);

    $header = $page->script(<<<'JS'
        (() => {
            const rect = (testId) => document.querySelector(`[data-testid="${testId}"]`).getBoundingClientRect();
            const labels = rect('composer-tags-trigger');
            const templates = rect('composer-templates-toggle');
            const header = document.querySelector('[data-testid="composer-header"]');
            return {
                overlap: labels.right > templates.left && labels.top < templates.bottom && templates.top < labels.bottom,
                templatesWidth: templates.width,
                eyeHidden: getComputedStyle(document.querySelector('[data-testid="composer-preview-toggle"]')).display === 'none',
                headerOverflow: header.scrollWidth > header.clientWidth,
            };
        })()
    JS);

    expect($header['overlap'])->toBeFalse()
        ->and($header['templatesWidth'])->toBeLessThanOrEqual(33)
        ->and($header['eyeHidden'])->toBeTrue()
        ->and($header['headerOverflow'])->toBeFalse();

    $page->assertAttribute('@composer-view-edit', 'aria-pressed', 'true')
        ->assertAttribute('@composer-view-preview', 'aria-pressed', 'false')
        ->assertAttribute('@composer-templates-toggle', 'aria-pressed', 'false')
        ->assertAttribute('@composer-ai-assistant', 'aria-pressed', 'false');

    selectAllComposerMobileChannels($page);
    $page->fill('@composer-base-content', 'Launching our new feature today! A longer paragraph of text #launch #saas')
        ->click('@composer-next');

    foreach ([$x, $tiktok] as $account) {
        openComposerMobileNetworkCard($page, $account);

        $layout = $page->script(<<<'JS'
            (() => {
                const column = document.querySelector('[data-testid="composer-editor-column"]');
                const card = document.querySelector('[data-testid="composer-customization"]');
                const bounds = card.getBoundingClientRect();
                const overflowing = [...card.querySelectorAll('*')]
                    .filter((element) => {
                        const rect = element.getBoundingClientRect();
                        return rect.width > 0 && (rect.right > bounds.right + 1 || rect.left < bounds.left - 1);
                    })
                    .map((element) => element.dataset.testid ?? element.tagName);
                return {
                    columnWidth: column.clientWidth,
                    captionWidth: card.querySelector('textarea').clientWidth,
                    overflowing,
                    pageOverflow: document.documentElement.scrollWidth > window.innerWidth,
                };
            })()
        JS);

        expect($layout['columnWidth'])->toBeGreaterThanOrEqual(600)
            ->and($layout['captionWidth'])->toBeGreaterThanOrEqual(480)
            ->and($layout['overflowing'])->toBe([])
            ->and($layout['pageOverflow'])->toBeFalse();
    }

    $page->assertNoJavaScriptErrors();
});

test('below lg the edit and preview control switches between the editor and the preview', function () {
    [$user, $workspace] = composerMobileWorkspace();
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $page = openComposerMobile($this, $user, 390, 844);
    selectComposerMobileChannel($page, $account);
    waitForComposerMobileTestId($page, "composer-caption-{$account->id}");
    $page->fill("@composer-caption-{$account->id}", 'Seen in the preview')
        ->click('@composer-view-preview');
    waitForComposerMobileTestId($page, 'composer-preview-frame');

    $page->assertVisible('@composer-preview-frame')
        ->assertMissing("@composer-caption-{$account->id}")
        ->assertAttribute('@composer-view-preview', 'aria-pressed', 'true')
        ->click('@composer-view-edit');
    waitForComposerMobileTestId($page, "composer-caption-{$account->id}");

    $page->assertValue("@composer-caption-{$account->id}", 'Seen in the preview')
        ->assertMissing('@composer-preview-frame')
        ->assertAttribute('@composer-view-edit', 'aria-pressed', 'true')
        ->assertNoJavaScriptErrors();
});

test('below lg templates and the assistant open as a bottom sheet and closing keeps the text', function (int $width, int $height) {
    [$user] = composerMobileWorkspace();

    $page = openComposerMobile($this, $user, $width, $height);
    $page->fill('@composer-base-content', 'Keep this draft');

    foreach (['templates' => 'composer-templates-toggle', 'assistant' => 'composer-ai-assistant'] as $panel => $toggle) {
        $page->click("@{$toggle}");
        waitForComposerMobileSettled($page, "composer-{$panel}-sheet");

        $sheet = composerMobileRect($page, "composer-{$panel}-sheet");
        expect(abs($sheet['bottom'] - $sheet['viewportHeight']))->toBeLessThan(2)
            ->and($sheet['top'])->toBeGreaterThan(0)
            ->and(abs($sheet['width'] - $sheet['viewportWidth']))->toBeLessThan(2)
            ->and($page->script("!!document.querySelector('[data-testid=\"composer-{$panel}-sheet\"] [data-testid=\"composer-{$panel}-panel\"]')"))->toBeTrue();
        $page->assertAttribute("@{$toggle}", 'aria-pressed', 'true')
            ->click("@composer-{$panel}-sheet-close");
        waitForComposerMobileGone($page, "composer-{$panel}-sheet");

        $page->assertValue('@composer-base-content', 'Keep this draft')
            ->assertAttribute("@{$toggle}", 'aria-pressed', 'false');
    }

    $page->assertNoJavaScriptErrors();
})->with([[390, 844]]);

test('at 390px the schedule menu, date picker and labels open as bottom sheets inside the viewport', function () {
    [$user, $workspace] = composerMobileWorkspace();
    SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);

    $page = openComposerMobile($this, $user, 390, 844);
    $page->click('@composer-schedule-trigger');
    waitForComposerMobileSettled($page, 'composer-schedule-sheet');

    $sheet = composerMobileRect($page, 'composer-schedule-sheet');
    expect($sheet['top'])->toBeGreaterThanOrEqual(0)
        ->and(abs($sheet['bottom'] - $sheet['viewportHeight']))->toBeLessThan(2)
        ->and($sheet['left'])->toBeGreaterThanOrEqual(0)
        ->and($sheet['right'])->toBeLessThanOrEqual($sheet['viewportWidth'])
        ->and($page->script("!!document.querySelector('[data-testid=\"composer-schedule-sheet\"] [data-testid=\"composer-schedule-now\"]')"))->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-reka-popper-content-wrapper]').length"))->toBe(0);

    $page->click('@composer-schedule-custom');
    waitForComposerMobileTestId($page, 'composer-schedule-picker');

    $picker = $page->script(<<<'JS'
        (() => {
            const sheet = document.querySelector('[data-testid="composer-schedule-sheet"]');
            const done = document.querySelector('[data-testid="composer-schedule-done"]').getBoundingClientRect();
            const day = document.querySelector('[data-testid^="composer-schedule-day-"]').getBoundingClientRect();
            return {
                inSheet: sheet.contains(document.querySelector('[data-testid="composer-schedule-picker"]')),
                doneVisible: done.top >= 0 && done.bottom <= window.innerHeight,
                dayHeight: day.height,
                sheetTop: sheet.getBoundingClientRect().top,
            };
        })()
    JS);
    expect($picker['inSheet'])->toBeTrue()
        ->and($picker['doneVisible'])->toBeTrue()
        ->and($picker['dayHeight'])->toBeGreaterThanOrEqual(44)
        ->and($picker['sheetTop'])->toBeGreaterThanOrEqual(0);

    $page->click('@composer-schedule-done');
    waitForComposerMobileGone($page, 'composer-schedule-sheet');
    $page->assertAttribute('@composer-submit', 'data-schedule-mode', 'custom');

    $page->click('@composer-tags-trigger');
    waitForComposerMobileSettled($page, 'composer-label-sheet');

    $labels = composerMobileRect($page, 'composer-label-sheet');
    expect($labels['top'])->toBeGreaterThanOrEqual(0)
        ->and(abs($labels['bottom'] - $labels['viewportHeight']))->toBeLessThan(2)
        ->and($page->script("!!document.querySelector('[data-testid=\"composer-label-sheet\"] [data-testid=\"composer-label-search\"]')"))->toBeTrue();

    $page->click('@composer-label-close');
    waitForComposerMobileGone($page, 'composer-label-sheet');
    $page->assertNoJavaScriptErrors();
});

test('touch devices get no tooltips in the composer', function () {
    [$user, $workspace] = composerMobileWorkspace();
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'))->on()->mobile()->resize(390, 844);
    waitForComposerMobileSettled($page, 'post-composer-dialog');
    waitForComposerMobileTestId($page, 'composer-add-account');

    expect($page->script("matchMedia('(hover: hover)').matches"))->toBeFalse();

    selectComposerMobileChannel($page, $account);
    $page->click("@composer-account-{$account->id}");
    expect(composerMobileTooltipCount($page))->toBe(0);

    $page->click('@composer-schedule-trigger');
    waitForComposerMobileSettled($page, 'composer-schedule-sheet');
    $page->hover('@composer-schedule-default-next');
    expect(composerMobileTooltipCount($page))->toBe(0)
        ->and($page->script('getComputedStyle(document.querySelector(\'[data-testid="composer-schedule-default-now"]\')).opacity'))->toBe('1');

    $page->assertNoJavaScriptErrors();
});

test('the open network card keeps its header pinned while its card scrolls', function () {
    [$user, $workspace] = composerMobileWorkspace();
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $page = openComposerMobile($this, $user, 390, 600);
    selectAllComposerMobileChannels($page);
    $page->fill('@composer-base-content', 'Sticky header')->click('@composer-next');
    openComposerMobileNetworkCard($page, $instagram);
    waitForComposerMobileTestId($page, "composer-{$instagram->id}-header");

    $pinned = $page->script(<<<JS
        (async () => {
            const column = document.querySelector('[data-testid="composer-editor-column"]');
            const card = document.querySelector('[data-testid="composer-customization"]');
            column.scrollTop += card.getBoundingClientRect().top - column.getBoundingClientRect().top + 120;
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const top = column.getBoundingClientRect().top;
            const header = document.querySelector('[data-testid="composer-{$instagram->id}-header"]').getBoundingClientRect();
            const logo = document.querySelector('[data-testid="composer-{$instagram->id}-logo"]').getBoundingClientRect();
            return {
                cardTop: card.getBoundingClientRect().top - top,
                cardHeight: card.getBoundingClientRect().height,
                cardAbove: card.getBoundingClientRect().top < top - 100,
                headerOffset: header.top - top,
                logoOffset: logo.top - top,
                headerOnTop: document.elementFromPoint(header.left + 4, header.top + 4)?.closest('[data-testid="composer-{$instagram->id}-header"]') !== null,
            };
        })()
    JS);

    expect($pinned['cardAbove'])->toBeTrue(json_encode($pinned))
        ->and($pinned['headerOffset'])->toBeGreaterThanOrEqual(0)->toBeLessThan(24)
        ->and($pinned['logoOffset'])->toBe($pinned['headerOffset'])
        ->and($pinned['headerOnTop'])->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('at 390px the footer is compact and the channel strip hints at hidden chips', function () {
    [$user, $workspace] = composerMobileWorkspace();
    SocialAccount::factory()->count(8)->x()->create(['workspace_id' => $workspace->id]);

    $page = openComposerMobile($this, $user, 390, 844);
    selectAllComposerMobileChannels($page);
    waitForComposerMobileCondition($page, "document.querySelector('[data-testid=\"composer-accounts\"]')?.hasAttribute('data-overflowing')");

    $footer = composerMobileRect($page, 'composer-footer');
    expect($footer['height'])->toBeLessThanOrEqual(104)
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"composer-accounts\"]')).maskImage"))->toContain('linear-gradient');

    $page->script("(() => { const strip = document.querySelector('[data-testid=\"composer-accounts\"]'); strip.scrollLeft = strip.scrollWidth; })()");
    waitForComposerMobileCondition($page, "!document.querySelector('[data-testid=\"composer-accounts\"]').hasAttribute('data-overflowing')");

    expect($page->script("document.querySelector('[data-testid=\"composer-accounts\"]').hasAttribute('data-overflowing')"))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('the edit and preview control fits on one line in every language', function () {
    [$user] = composerMobileWorkspace();
    $page = openComposerMobile($this, $user->fresh(), 390, 844);
    waitForComposerMobileTestId($page, 'composer-view-switch');

    $translations = collect(Locale::cases())
        ->mapWithKeys(fn (Locale $locale): array => [
            $locale->value => [
                'edit' => __('posts.composer.edit_view', [], $locale->value),
                'preview' => __('posts.edit.tabs.preview', [], $locale->value),
            ],
        ])
        ->all();

    $json = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT);

    $wrapped = $page->script(<<<JS
        (() => {
            const translations = {$json};
            const wrapped = [];

            Object.entries(translations).forEach(([locale, labels]) => {
                Object.entries(labels).forEach(([view, text]) => {
                    const button = document.querySelector('[data-testid="composer-view-' + view + '"]');
                    const label = button.querySelector('[data-single-line]');
                    label.textContent = text;
                    const range = document.createRange();
                    range.selectNodeContents(label);
                    const lines = new Set([...range.getClientRects()].filter((rect) => rect.width > 0).map((rect) => Math.round(rect.top)));

                    if (lines.size > 1 || button.scrollWidth > button.clientWidth) {
                        wrapped.push(locale + ': ' + text);
                    }
                });
            });

            return wrapped;
        })()
    JS);

    expect($wrapped)->toBe([]);
    $page->assertNoJavaScriptErrors();
});
