<?php

declare(strict_types=1);

use App\Enums\PostTemplate\Visibility;
use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;

function waitForCreateTemplatesTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                if (document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForCreateTemplatesCondition(mixed $page, string $condition): void
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

/**
 * Dialogs animate in; a click that lands before the animation settles is swallowed.
 */
function waitForCreateTemplatesDialog(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 200; attempt++) {
                const dialog = document.querySelector('[data-testid="{$testId}"]');
                if (dialog?.getAttribute('data-state') === 'open'
                    && dialog.getAnimations().every((animation) => animation.playState !== 'running')) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForCreateTemplatesDatabase(mixed $page, Closure $condition): void
{
    for ($attempt = 0; $attempt < 50 && ! $condition(); $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

function createTemplatesCardCount(mixed $page, string $scopeTestId): int
{
    return $page->script("document.querySelectorAll('[data-testid=\"{$scopeTestId}\"] article[data-testid^=\"template-card-\"]').length");
}

/**
 * @return array{0: User, 1: Workspace}
 */
function createTemplatesSetup(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot($role));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user->fresh(), $workspace];
}

test('discover shows the featured band, capped rows and the scope counts', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'templates-featured');

    $page->assertVisible('@create-tab-templates')
        ->assertAttribute('@create-tab-templates', 'aria-current', 'page')
        ->assertVisible('@templates-featured')
        ->assertVisible('@template-card-quick_win')
        ->assertVisible('@templates-row-see-all-story')
        ->assertSeeIn('@templates-scope-count-discover', '45')
        ->assertNoJavaScriptErrors();

    expect(createTemplatesCardCount($page, 'templates-row-story'))->toBe(10)
        ->and(createTemplatesCardCount($page, 'templates-featured'))->toBe(3);
});

test('the filter narrows the library, updates the url and resets', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'templates-filter');
    $page->script(<<<'JS'
        (() => {
            window.__sawEmptyState = false;
            new MutationObserver(() => {
                if (document.querySelector('[data-testid="templates-empty"]')) window.__sawEmptyState = true;
            }).observe(document.body, { childList: true, subtree: true });
        })()
    JS);
    $page->click('@templates-filter');
    waitForCreateTemplatesTestId($page, 'templates-filter-type-question');
    $page->click('@templates-filter-type-question');
    waitForCreateTemplatesTestId($page, 'templates-results');

    expect($page->script('window.__sawEmptyState'))->toBeFalse();

    expect(createTemplatesCardCount($page, 'templates-results'))->toBe(2)
        ->and(urldecode($page->script('window.location.search')))->toMatch('/types\[\d*\]=question/');

    $page->click('@templates-filter-reset');
    waitForCreateTemplatesTestId($page, 'templates-featured');

    $page->assertVisible('@templates-row-story')->assertNoJavaScriptErrors();
});

test('a library card opens its detail in place and use template opens the composer', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'template-card-origin_story');
    $page->click('@template-card-origin_story');
    waitForCreateTemplatesDialog($page, 'template-detail');

    expect($page->script('window.location.pathname + window.location.search'))->toBe(route('app.create.templates.index', [], false));

    $page->click('@template-use');
    waitForCreateTemplatesTestId($page, 'composer-base-content');

    $body = __('template_library.origin_story.body');
    $page->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', $body)
        ->assertMissing('@template-detail')
        ->assertNoJavaScriptErrors();
});

test('creating a personal template shows the card without a success toast', function () {
    [$user, $workspace] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'templates-scope-personal');
    $page->click('@templates-scope-personal');
    waitForCreateTemplatesTestId($page, 'templates-empty');
    $page->click('@templates-new');
    waitForCreateTemplatesDialog($page, 'template-editor');
    $page->fill('@template-editor-title', 'Weekly recap')
        ->fill('@template-editor-body', "• Win\n• Lesson");
    $page->click('@template-editor-save');
    waitForCreateTemplatesCondition($page, "document.querySelectorAll('article[data-testid^=\"template-card-\"]').length === 1");

    $page->assertSeeIn('@templates-grid', 'Weekly recap')
        ->assertSeeIn('@templates-scope-count-personal', '1')
        ->assertMissing('@template-editor');

    expect($page->script("document.querySelectorAll('[data-sonner-toast]').length"))->toBe(0)
        ->and(PostTemplate::query()->where('workspace_id', $workspace->id)->sole()->visibility)->toBe(Visibility::Personal);

    $page->assertNoJavaScriptErrors();
});

test('duplicating a library template into the team scope adds the copy suffix', function () {
    [$user, $workspace] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'template-card-quick_win');
    $page->click('@template-card-menu-quick_win');
    waitForCreateTemplatesTestId($page, 'template-duplicate-quick_win');
    $page->click('@template-duplicate-quick_win');
    waitForCreateTemplatesDialog($page, 'template-duplicate-dialog');
    $page->assertSeeIn('@template-duplicate-dialog', __('template_library.quick_win.title'))
        ->click('@template-duplicate-visibility');
    waitForCreateTemplatesTestId($page, 'template-duplicate-visibility-team');
    $page->click('@template-duplicate-visibility-team');
    $page->click('@template-duplicate-confirm');
    waitForCreateTemplatesCondition($page, "document.querySelectorAll('[data-testid=\"templates-grid\"] article').length === 1");

    $page->assertSeeIn('@templates-grid', __('template_library.quick_win.title').' (copy)')
        ->assertSeeIn('@templates-scope-count-team', '1')
        ->assertNoJavaScriptErrors();

    $copy = PostTemplate::query()->where('workspace_id', $workspace->id)->sole();

    expect(urldecode($page->script('window.location.search')))->toContain('view=team')
        ->and($copy->title)->toBe(__('template_library.quick_win.title').' (copy)')
        ->and($copy->visibility)->toBe(Visibility::Team)
        ->and($copy->user_id)->toBe($user->id);
});

test('deleting a template asks for confirmation without typing a keyword', function () {
    [$user, $workspace] = createTemplatesSetup();
    $template = PostTemplate::factory()->team()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'title' => 'Doomed']);
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index', ['view' => 'team']));
    waitForCreateTemplatesTestId($page, "template-card-{$template->id}");
    $page->click("@template-card-menu-{$template->id}");
    waitForCreateTemplatesTestId($page, "template-delete-{$template->id}");
    $page->click("@template-delete-{$template->id}");
    waitForCreateTemplatesDialog($page, 'confirm-delete-modal');
    $page->assertMissing('@confirm-delete-input')
        ->click('@template-delete-confirm');

    waitForCreateTemplatesDatabase($page, fn () => PostTemplate::query()->whereKey($template->id)->doesntExist());
    waitForCreateTemplatesCondition($page, "!document.querySelector('[data-testid=\"template-card-{$template->id}\"]')");

    expect(PostTemplate::query()->whereKey($template->id)->exists())->toBeFalse();
    $page->assertMissing("@template-card-{$template->id}")->assertNoJavaScriptErrors();
});

test('editing a template from its menu updates the card and the row', function () {
    [$user, $workspace] = createTemplatesSetup();
    $template = PostTemplate::factory()->personal($user)->create(['workspace_id' => $workspace->id, 'title' => 'Draft title']);
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index', ['view' => 'personal']));
    waitForCreateTemplatesTestId($page, "template-card-{$template->id}");
    $page->click("@template-card-menu-{$template->id}");
    waitForCreateTemplatesTestId($page, "template-edit-{$template->id}");
    $page->click("@template-edit-{$template->id}");
    waitForCreateTemplatesDialog($page, 'template-editor');

    expect($page->script('window.location.pathname + window.location.search'))->toBe(route('app.create.templates.index', ['view' => 'personal'], false));

    $page->assertValue('@template-editor-title', 'Draft title')
        ->fill('@template-editor-title', 'Final title')
        ->fill('@template-editor-body', 'New body')
        ->click('@template-editor-save');
    waitForCreateTemplatesCondition($page, "(document.querySelector('[data-testid=\"templates-grid\"]')?.textContent ?? '').includes('Final title')");

    $template->refresh();

    expect($template->title)->toBe('Final title')
        ->and($template->body)->toBe('New body')
        ->and($template->visibility)->toBe(Visibility::Personal);
    $page->assertMissing('@template-editor')
        ->assertSeeIn('@templates-grid', 'Final title')
        ->assertNoJavaScriptErrors();
});

test('the emoji picker sets the template emoji', function () {
    [$user, $workspace] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'templates-new');
    $page->click('@templates-new');
    waitForCreateTemplatesDialog($page, 'template-editor');
    $page->assertSeeIn('@template-editor-emoji', '📝')
        ->click('@template-editor-emoji');
    waitForCreateTemplatesTestId($page, 'emoji-picker-option');

    $gap = $page->script(<<<'JS'
        (async () => {
            const scroller = document.querySelector('[data-testid="emoji-picker-scroll"]');
            scroller.scrollTop = 600;
            await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
            const top = scroller.getBoundingClientRect().top;
            const stuck = [...document.querySelectorAll('[data-testid="emoji-picker-category-header"]')]
                .map((header) => header.getBoundingClientRect().top - top)
                .filter((offset) => offset >= -1 && offset < 40);
            return Math.round(Math.min(...stuck));
        })();
    JS);
    expect($gap)->toBe(0);

    $shadows = $page->script(<<<'JS'
        (() => {
            const top = document.querySelector('[data-testid="emoji-picker-scroll"]').getBoundingClientRect().top;
            return [...document.querySelectorAll('[data-testid="emoji-picker-category-header"]')]
                .filter((header) => Math.abs(header.getBoundingClientRect().top - top) < 1)
                .map((header) => getComputedStyle(header).boxShadow);
        })()
    JS);
    expect($shadows)->not->toBeEmpty()
        ->and($shadows[0])->not->toBe('none');

    $emoji = $page->script(<<<'JS'
        (() => {
            const option = document.querySelector('[data-testid="emoji-picker-option"]');
            option.click();

            return option.textContent.trim();
        })()
    JS);

    waitForCreateTemplatesCondition($page, '!document.querySelector(\'[data-testid="emoji-picker-option"]\')');
    $page->assertSeeIn('@template-editor-emoji', $emoji)
        ->fill('@template-editor-title', 'With emoji')
        ->fill('@template-editor-body', 'Body')
        ->click('@template-editor-save');
    waitForCreateTemplatesDatabase($page, fn () => PostTemplate::query()->where('workspace_id', $workspace->id)->exists());

    expect($emoji)->not->toBe('📝')
        ->and(PostTemplate::query()->where('workspace_id', $workspace->id)->sole()->emoji)->toBe($emoji);
    $page->assertNoJavaScriptErrors();
});

test('a card opens with Enter and Space from its own button', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'template-card-open-origin_story');

    expect($page->script("document.querySelector('[data-testid=\"template-card-origin_story\"]').hasAttribute('role')"))->toBeFalse();

    $page->keys('@template-card-open-origin_story', 'Enter');
    waitForCreateTemplatesDialog($page, 'template-detail');
    $page->assertVisible('@template-detail')
        ->keys('@template-detail', 'Escape');
    waitForCreateTemplatesCondition($page, "!document.querySelector('[data-testid=\"template-detail\"]')");

    $page->assertMissing('@template-detail')
        ->keys('@template-card-open-origin_story', 'Space');
    waitForCreateTemplatesDialog($page, 'template-detail');

    $page->assertVisible('@template-detail')->assertNoJavaScriptErrors();
});

test('only the creator can change the visibility of a team template', function () {
    [$alice, $workspace] = createTemplatesSetup();
    $bob = User::factory()->create(['account_id' => $workspace->account_id]);
    $workspace->members()->attach($bob->id, membershipPivot('member'));
    $bob->update(['current_workspace_id' => $workspace->id]);
    $template = PostTemplate::factory()->team()->create(['workspace_id' => $workspace->id, 'user_id' => $alice->id]);

    $this->actingAs($bob->fresh());
    $page = visit(route('app.create.templates.index', ['view' => 'team']));
    waitForCreateTemplatesTestId($page, "template-card-menu-{$template->id}");
    $page->click("@template-card-menu-{$template->id}");
    waitForCreateTemplatesTestId($page, "template-edit-{$template->id}");
    $page->click("@template-edit-{$template->id}");
    waitForCreateTemplatesDialog($page, 'template-editor');

    $page->assertVisible('@template-editor-visibility-hint')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelector('[data-testid=\"template-editor-visibility\"]').disabled"))->toBeTrue();
});

test('dialogs put cancel before the primary action', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'templates-new');
    $page->click('@templates-new');
    waitForCreateTemplatesDialog($page, 'template-editor');

    $editorOrder = $page->script(<<<'JS'
        document.querySelector('[data-testid="template-editor-cancel"]').compareDocumentPosition(document.querySelector('[data-testid="template-editor-save"]')) & Node.DOCUMENT_POSITION_FOLLOWING
    JS);

    $page->click('@template-editor-cancel');
    waitForCreateTemplatesTestId($page, 'template-card-quick_win');
    $page->click('@template-card-menu-quick_win');
    waitForCreateTemplatesTestId($page, 'template-duplicate-quick_win');
    $page->click('@template-duplicate-quick_win');
    waitForCreateTemplatesDialog($page, 'template-duplicate-dialog');

    $duplicateOrder = $page->script(<<<'JS'
        document.querySelector('[data-testid="template-duplicate-cancel"]').compareDocumentPosition(document.querySelector('[data-testid="template-duplicate-confirm"]')) & Node.DOCUMENT_POSITION_FOLLOWING
    JS);

    expect($editorOrder)->toBeTruthy()
        ->and($duplicateOrder)->toBeTruthy();
    $page->assertNoJavaScriptErrors();
});

test('a matching discover search keeps the rows until the results arrive', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'templates-search');
    $page->script(<<<'JS'
        (() => {
            window.__sawEmptyState = false;
            new MutationObserver(() => {
                if (document.querySelector('[data-testid="templates-empty"]')) window.__sawEmptyState = true;
            }).observe(document.body, { childList: true, subtree: true });
        })()
    JS);
    $page->click('@templates-search');
    waitForCreateTemplatesTestId($page, 'templates-search-input');
    $page->fill('@templates-search-input', 'five-minute');
    waitForCreateTemplatesTestId($page, 'templates-results');

    expect($page->script('window.__sawEmptyState'))->toBeFalse()
        ->and(createTemplatesCardCount($page, 'templates-results'))->toBeGreaterThan(0);

    $page->assertNoJavaScriptErrors();
});

test('the detail and the editor close with the close button, escape and an outside click without touching the url', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $path = route('app.create.templates.index', [], false);
    $page = visit(route('app.create.templates.index'));
    $closers = [
        fn () => $page->click('@dialog-close'),
        fn () => $page->keys('@template-detail', 'Escape'),
        fn () => $page->script("document.body.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true }))"),
    ];

    foreach (range(1, 2) as $round) {
        foreach ($closers as $close) {
            waitForCreateTemplatesTestId($page, 'template-card-origin_story');
            $page->click('@template-card-origin_story');
            waitForCreateTemplatesDialog($page, 'template-detail');
            $close();
            waitForCreateTemplatesCondition($page, "!document.querySelector('[data-testid=\"template-detail\"]')");

            $page->assertMissing('@template-detail');
            expect($page->script('window.location.pathname + window.location.search'))->toBe($path);
        }
    }

    $page->click('@templates-new');
    waitForCreateTemplatesDialog($page, 'template-editor');
    $page->keys('@template-editor', 'Escape');
    waitForCreateTemplatesCondition($page, "!document.querySelector('[data-testid=\"template-editor\"]')");

    $page->assertMissing('@template-editor')->assertNoJavaScriptErrors();
    expect($page->script('window.location.pathname + window.location.search'))->toBe($path);
});

test('the visibility select shows a user icon for personal and the channels grid icon for team', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index'));
    waitForCreateTemplatesTestId($page, 'templates-scope-personal');
    $page->click('@templates-scope-personal');
    waitForCreateTemplatesTestId($page, 'templates-empty');
    $page->click('@templates-new');
    waitForCreateTemplatesDialog($page, 'template-editor');

    $page->assertVisible('@template-editor-visibility-icon-personal');

    $page->click('@template-editor-visibility');
    waitForCreateTemplatesTestId($page, 'template-editor-visibility-team');

    expect($page->script("document.querySelectorAll('[data-testid^=\"template-editor-visibility-\"] svg').length"))->toBeGreaterThanOrEqual(2);

    $page->click('@template-editor-visibility-team');
    waitForCreateTemplatesTestId($page, 'template-editor-visibility-icon-team');

    expect($page->script("document.querySelector('[data-testid=\"template-editor-visibility-icon-team\"]').classList.contains('tabler-icon-layout-grid')"))->toBeTrue();

    $page->assertVisible('@template-editor-visibility-icon-team')
        ->assertMissing('@template-editor-visibility-icon-personal')
        ->assertNoJavaScriptErrors();
});

test('clicking the active scope again does not duplicate its templates', function (string $scope) {
    [$user, $workspace] = createTemplatesSetup();
    $factory = $scope === 'team' ? PostTemplate::factory()->team() : PostTemplate::factory()->personal($user);
    $factory->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'title' => 'Only one']);
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index', ['view' => $scope]));
    waitForCreateTemplatesCondition($page, "document.querySelectorAll('article[data-testid^=\"template-card-\"]').length === 1");

    foreach (range(1, 3) as $attempt) {
        $page->click("@templates-scope-{$scope}");
        $page->script('new Promise((resolve) => setTimeout(resolve, 600))');
    }

    expect($page->script("document.querySelectorAll('article[data-testid^=\"template-card-\"]').length"))->toBe(1);

    $page->click('@templates-scope-discover');
    waitForCreateTemplatesTestId($page, 'templates-featured');
    $page->click("@templates-scope-{$scope}");
    waitForCreateTemplatesCondition($page, "document.querySelectorAll('article[data-testid^=\"template-card-\"]').length >= 1");
    $page->script('new Promise((resolve) => setTimeout(resolve, 600))');

    expect($page->script("document.querySelectorAll('article[data-testid^=\"template-card-\"]').length"))->toBe(1);

    $page->assertNoJavaScriptErrors();
})->with(['personal', 'team']);

test('scope chips are links to their scope', function () {
    [$user] = createTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.create.templates.index', ['view' => 'team']));
    waitForCreateTemplatesTestId($page, 'templates-scope-personal');

    $links = $page->script(<<<'JS'
        ['discover', 'team', 'personal'].map((scope) => {
            const chip = document.querySelector(`[data-testid="templates-scope-${scope}"]`);

            return [chip.tagName, chip.getAttribute('href'), chip.getAttribute('aria-current')];
        })
    JS);

    expect($links)->toBe([
        ['A', parse_url(route('app.create.templates.index'), PHP_URL_PATH), null],
        ['A', parse_url(route('app.create.templates.index'), PHP_URL_PATH).'?view=team', 'page'],
        ['A', parse_url(route('app.create.templates.index'), PHP_URL_PATH).'?view=personal', null],
    ]);
    $page->assertNoJavaScriptErrors();
});
