<?php

declare(strict_types=1);

use App\Enums\PostTemplate\Visibility;
use App\Enums\SocialAccount\Platform;
use App\Models\PostTemplate;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForComposerTemplatesTestId(mixed $page, string $testId): void
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

function waitForComposerTemplatesCondition(mixed $page, string $condition): void
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
function waitForComposerTemplatesDialog(mixed $page, string $testId): void
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

function waitForComposerTemplatesDatabase(mixed $page, Closure $condition): void
{
    for ($attempt = 0; $attempt < 50 && ! $condition(); $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

function composerTemplatesListHas(string $text): string
{
    $expected = json_encode($text);

    return "(document.querySelector('[data-testid=\"composer-templates-list\"]')?.textContent ?? '').includes({$expected})";
}

/**
 * @return array{0: User, 1: Workspace}
 */
function composerTemplatesSetup(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return [$user->fresh(), $workspace];
}

function openComposerTemplates(User $user, string $content = ''): mixed
{
    test()->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerTemplatesDialog($page, 'post-composer-dialog');
    waitForComposerTemplatesTestId($page, 'composer-base-content');

    if ($content !== '') {
        $page->fill('@composer-base-content', $content);
    }

    $page->click('@composer-templates-toggle');
    waitForComposerTemplatesTestId($page, 'composer-template-quick_win');

    return $page;
}

test('a library card appends its body after the draft', function () {
    [$user] = composerTemplatesSetup();

    $page = openComposerTemplates($user, 'Hello');
    $page->click('@composer-template-quick_win');

    $page->assertValue('@composer-base-content', "Hello\n\n".__('template_library.quick_win.body'))
        ->assertVisible('@composer-templates-panel')
        ->assertNoJavaScriptErrors();
});

test('the discover filter is an icon-only button', function () {
    [$user] = composerTemplatesSetup();

    $page = openComposerTemplates($user);
    waitForComposerTemplatesTestId($page, 'composer-templates-filter');

    expect($page->script("document.querySelector('[data-testid=\"composer-templates-filter\"]').innerText.trim()"))->toBe('')
        ->and($page->script("document.querySelector('[data-testid=\"composer-templates-filter\"]').getAttribute('aria-label')"))->toBe(__('create.templates.filter'));
    $page->assertNoJavaScriptErrors();
});

test('the scopes are underline tabs, not pills', function () {
    [$user] = composerTemplatesSetup();

    $page = openComposerTemplates($user);

    $underline = fn (string $scope): string => $page->script("getComputedStyle(document.querySelector('[data-testid=\"composer-templates-tab-{$scope}\"]'), '::after').opacity");

    expect($underline('discover'))->toBe('1')
        ->and($underline('team'))->toBe('0')
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"composer-templates-tab-discover\"]')).backgroundColor"))->toBe('rgba(0, 0, 0, 0)')
        ->and($page->script("(() => { const probe = document.createElement('span'); probe.className = 'bg-primary-text'; document.body.append(probe); const green = getComputedStyle(probe).backgroundColor; probe.remove(); return getComputedStyle(document.querySelector('[data-testid=\"composer-templates-tab-discover\"]'), '::after').backgroundColor === green; })()"))->toBeTrue()
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"composer-templates-new\"]')).borderTopWidth"))->toBe('1px');
    $page->assertNoJavaScriptErrors();
});

test('in the per-network view a template goes to the expanded account override only', function () {
    [$user, $workspace] = composerTemplatesSetup();
    $linkedIn = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $x = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $template = PostTemplate::factory()->team()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'body' => 'Team body']);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerTemplatesDialog($page, 'post-composer-dialog');
    waitForComposerTemplatesTestId($page, 'composer-add-account');
    $page->fill('@composer-base-content', 'Hello world')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$linkedIn->id}")
        ->click("@composer-account-option-{$x->id}")
        ->click('@composer-next');
    waitForComposerTemplatesTestId($page, 'composer-customization');
    $page->click("@composer-account-{$linkedIn->id}");
    waitForComposerTemplatesTestId($page, "composer-caption-{$linkedIn->id}");
    $page->click('@composer-templates-toggle')
        ->click('@composer-templates-tab-team');
    waitForComposerTemplatesTestId($page, "composer-template-{$template->id}");
    $page->click("@composer-template-{$template->id}");

    $page->assertValue("@composer-caption-{$linkedIn->id}", "Hello world\n\nTeam body")
        ->click("@composer-account-{$x->id}");
    waitForComposerTemplatesTestId($page, "composer-caption-{$x->id}");

    $page->assertValue("@composer-caption-{$x->id}", 'Hello world')
        ->assertNoJavaScriptErrors();
});

test('the personal tab lists only the actor templates with their badge and search narrows it', function () {
    [$user, $workspace] = composerTemplatesSetup();
    $teammate = User::factory()->create(['account_id' => $workspace->account_id]);
    $workspace->members()->attach($teammate->id, membershipPivot('member'));
    $mine = PostTemplate::factory()->personal($user)->create(['workspace_id' => $workspace->id, 'title' => 'Monday recap']);
    $other = PostTemplate::factory()->personal($user)->create(['workspace_id' => $workspace->id, 'title' => 'Friday wins']);
    $theirs = PostTemplate::factory()->personal($teammate)->create(['workspace_id' => $workspace->id, 'title' => 'Secret draft']);

    $page = openComposerTemplates($user);
    $page->click('@composer-templates-tab-personal');
    waitForComposerTemplatesTestId($page, "composer-template-{$mine->id}");

    $page->assertVisible("@composer-template-{$other->id}")
        ->assertMissing("@composer-template-{$theirs->id}")
        ->assertSeeIn("@composer-template-visibility-{$mine->id}", __('create.templates.visibility.personal'))
        ->fill('@composer-templates-search', 'Monday');
    waitForComposerTemplatesCondition($page, "!document.querySelector('[data-testid=\"composer-template-{$other->id}\"]')");

    $page->assertVisible("@composer-template-{$mine->id}")
        ->assertMissing("@composer-template-{$other->id}")
        ->assertNoJavaScriptErrors();
});

test('the AI assistant and preview modes still work and only one toggle is pressed', function () {
    [$user] = composerTemplatesSetup();

    $page = openComposerTemplates($user);
    $pressed = "document.querySelectorAll('[data-testid^=\"composer-\"][aria-pressed=\"true\"]').length";

    $page->assertAttribute('@composer-templates-toggle', 'aria-pressed', 'true')
        ->click('@composer-ai-assistant')
        ->assertVisible('@composer-assistant-panel')
        ->assertMissing('@composer-templates-panel')
        ->assertAttribute('@composer-ai-assistant', 'aria-pressed', 'true');

    expect($page->script($pressed))->toBe(1);

    $page->click('@composer-preview-toggle')
        ->assertVisible('@composer-previews-scroll')
        ->assertMissing('@composer-assistant-panel')
        ->assertAttribute('@composer-preview-toggle', 'aria-pressed', 'true')
        ->assertAttribute('@composer-templates-toggle', 'aria-pressed', 'false')
        ->assertNoJavaScriptErrors();

    expect($page->script($pressed))->toBe(1);
});

test('the new template button creates a team template without touching the draft', function () {
    [$user, $workspace] = composerTemplatesSetup();

    $page = openComposerTemplates($user, 'Hello');
    $page->click('@composer-templates-tab-team')
        ->click('@composer-templates-new');
    waitForComposerTemplatesDialog($page, 'template-editor');

    $page->assertValue('@template-editor-body', '')
        ->assertSeeIn('@template-editor-visibility', __('create.templates.visibility.team'))
        ->fill('@template-editor-title', 'Launch checklist')
        ->fill('@template-editor-body', "• Date\n• Owner")
        ->click('@template-editor-save');
    waitForComposerTemplatesCondition($page, composerTemplatesListHas('Launch checklist'));

    $template = PostTemplate::query()->where('workspace_id', $workspace->id)->sole();

    expect($template->title)->toBe('Launch checklist')
        ->and($template->visibility)->toBe(Visibility::Team)
        ->and($template->body)->toBe("• Date\n• Owner");

    $page->assertMissing('@template-editor')
        ->assertVisible("@composer-template-{$template->id}")
        ->assertAttribute('@composer-templates-tab-team', 'aria-selected', 'true')
        ->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', 'Hello')
        ->assertNoJavaScriptErrors();

    expect($page->script("document.querySelectorAll('[data-sonner-toast]').length"))->toBe(0);
});

test('edit, duplicate and delete from the panel never touch the draft', function () {
    [$user, $workspace] = composerTemplatesSetup();
    $template = PostTemplate::factory()->personal($user)->create(['workspace_id' => $workspace->id, 'title' => 'Old title']);

    $page = openComposerTemplates($user, 'Hello');
    $page->click('@composer-templates-tab-personal');
    waitForComposerTemplatesTestId($page, "composer-template-{$template->id}");

    $page->click("@composer-template-menu-{$template->id}");
    waitForComposerTemplatesTestId($page, "template-edit-{$template->id}");
    $page->click("@template-edit-{$template->id}");
    waitForComposerTemplatesDialog($page, 'template-editor');
    $page->assertValue('@template-editor-title', 'Old title')
        ->fill('@template-editor-title', 'New title')
        ->click('@template-editor-save');
    waitForComposerTemplatesCondition($page, composerTemplatesListHas('New title'));

    expect($template->fresh()->title)->toBe('New title');
    $page->assertMissing('@template-editor')
        ->assertValue('@composer-base-content', 'Hello');

    $page->click("@composer-template-menu-{$template->id}");
    waitForComposerTemplatesTestId($page, "template-duplicate-{$template->id}");
    $page->click("@template-duplicate-{$template->id}");
    waitForComposerTemplatesDialog($page, 'template-duplicate-dialog');
    $page->assertSeeIn('@template-duplicate-dialog', 'New title')
        ->click('@template-duplicate-visibility');
    waitForComposerTemplatesTestId($page, 'template-duplicate-visibility-team');
    $page->click('@template-duplicate-visibility-team')
        ->click('@template-duplicate-confirm');
    waitForComposerTemplatesCondition($page, composerTemplatesListHas('New title (copy)'));

    $copy = PostTemplate::query()->where('workspace_id', $workspace->id)->whereKeyNot($template->id)->sole();

    expect($copy->title)->toBe('New title (copy)')
        ->and($copy->visibility)->toBe(Visibility::Team);
    $page->assertAttribute('@composer-templates-tab-team', 'aria-selected', 'true')
        ->assertVisible("@composer-template-{$copy->id}")
        ->assertValue('@composer-base-content', 'Hello');

    $page->click("@composer-template-menu-{$copy->id}");
    waitForComposerTemplatesTestId($page, "template-delete-{$copy->id}");
    $page->click("@template-delete-{$copy->id}");
    waitForComposerTemplatesDialog($page, 'confirm-delete-modal');
    $page->assertMissing('@confirm-delete-input')
        ->click('@template-delete-confirm');
    waitForComposerTemplatesDatabase($page, fn () => PostTemplate::query()->whereKey($copy->id)->doesntExist());
    waitForComposerTemplatesCondition($page, "!document.querySelector('[data-testid=\"composer-template-{$copy->id}\"]')");

    expect(PostTemplate::query()->whereKey($copy->id)->exists())->toBeFalse()
        ->and(PostTemplate::query()->whereKey($template->id)->exists())->toBeTrue();
    $page->assertMissing("@composer-template-{$copy->id}")
        ->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', 'Hello')
        ->assertNoJavaScriptErrors();
});

test('the empty editor link opens the templates panel', function () {
    [$user] = composerTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerTemplatesDialog($page, 'post-composer-dialog');
    waitForComposerTemplatesTestId($page, 'composer-templates-inspire');

    $page->assertMissing('@composer-templates-panel')
        ->click('@composer-templates-inspire');
    waitForComposerTemplatesTestId($page, 'composer-templates-panel');

    $page->assertVisible('@composer-templates-panel')
        ->assertAttribute('@composer-templates-toggle', 'aria-pressed', 'true')
        ->fill('@composer-base-content', 'Typing')
        ->assertMissing('@composer-templates-inspire')
        ->assertNoJavaScriptErrors();
});

test('at 390px the header fits and every side panel toggle works', function () {
    [$user] = composerTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.create'))->resize(390, 844);
    waitForComposerTemplatesDialog($page, 'post-composer-dialog');
    waitForComposerTemplatesTestId($page, 'composer-templates-toggle');

    $overflow = $page->script(<<<'JS'
        (() => {
            const header = document.querySelector('[data-testid="composer-header"]');
            const toggles = ['composer-templates-toggle', 'composer-ai-assistant', 'composer-preview-toggle']
                .map((testId) => document.querySelector(`[data-testid="${testId}"]`).getBoundingClientRect());

            return header.scrollWidth > header.clientWidth
                || toggles.some((rect) => rect.left < 0 || rect.right > window.innerWidth);
        })()
    JS);

    expect($overflow)->toBeFalse();

    $page->click('@composer-templates-toggle');
    waitForComposerTemplatesTestId($page, 'composer-templates-panel');
    $page->assertVisible('@composer-templates-panel')
        ->click('@composer-mobile-compose')
        ->click('@composer-ai-assistant');
    waitForComposerTemplatesTestId($page, 'composer-assistant-panel');
    $page->assertVisible('@composer-assistant-panel')
        ->click('@composer-mobile-compose')
        ->click('@composer-preview-toggle');
    waitForComposerTemplatesTestId($page, 'composer-previews-scroll');

    $page->assertVisible('@composer-previews-scroll')
        ->assertAttribute('@composer-preview-toggle', 'aria-pressed', 'true')
        ->assertNoJavaScriptErrors();
});

test('a failed template load shows an inline error and retry recovers', function () {
    [$user] = composerTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerTemplatesDialog($page, 'post-composer-dialog');
    waitForComposerTemplatesTestId($page, 'composer-templates-toggle');
    $page->script(<<<'JS'
        (() => {
            const open = XMLHttpRequest.prototype.open;
            window.__restoreXhr = () => { XMLHttpRequest.prototype.open = open; };
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                const target = String(url).includes('/templates/picker') ? `${url}-broken` : url;
                return open.call(this, method, target, ...rest);
            };
        })()
    JS);
    $page->click('@composer-templates-toggle');
    waitForComposerTemplatesTestId($page, 'composer-templates-error');

    $page->assertVisible('@composer-templates-retry');
    $page->script('window.__restoreXhr()');
    $page->click('@composer-templates-retry');
    waitForComposerTemplatesTestId($page, 'composer-template-quick_win');

    $page->assertMissing('@composer-templates-error')->assertNoJavaScriptErrors();
});

test('the panel tabs are wired to their tabpanel', function () {
    [$user] = composerTemplatesSetup();

    $page = openComposerTemplates($user);

    $page->assertAttribute('@composer-templates-tab-discover', 'aria-controls', 'composer-templates-tabpanel')
        ->assertAttribute('@composer-templates-list', 'role', 'tabpanel')
        ->assertAttribute('@composer-templates-list', 'aria-labelledby', 'composer-templates-tab-discover')
        ->assertNoJavaScriptErrors();
});

test('at 390px picking a template returns to the compose view', function () {
    [$user] = composerTemplatesSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.create'))->resize(390, 844);
    waitForComposerTemplatesDialog($page, 'post-composer-dialog');
    waitForComposerTemplatesTestId($page, 'composer-templates-toggle');
    $page->click('@composer-templates-toggle');
    waitForComposerTemplatesTestId($page, 'composer-template-quick_win');
    $page->click('@composer-template-quick_win');
    waitForComposerTemplatesCondition($page, "document.querySelector('[data-testid=\"composer-templates-panel\"]')?.getBoundingClientRect().height === 0");
    waitForComposerTemplatesTestId($page, 'composer-base-content');

    $page->assertValue('@composer-base-content', __('template_library.quick_win.body'))
        ->assertNoJavaScriptErrors();
});
