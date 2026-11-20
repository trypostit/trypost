<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;

function waitForLabelPopoverUrl(mixed $page, string $condition): void
{
    waitForCondition($page, "((params) => {$condition})(new URLSearchParams(location.search))");
}

test('the label filter offers untagged, clears the selection and links to the labels page', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, '@posts-label-filter');
    $page->click('@posts-label-filter');
    waitFor($page, '@posts-label-untagged');

    $page->assertScript('document.querySelector("[data-testid=posts-label-settings]").getAttribute("href")', route('app.labels.index', absolute: false))
        ->assertPresent('@posts-label-clear')
        ->click('@posts-label-untagged-checkbox');
    waitForLabelPopoverUrl($page, "params.get('untagged') === '1'");

    $page->assertScript('new URLSearchParams(location.search).get("untagged")', '1')
        ->click("@posts-label-checkbox-{$label->id}");
    waitForLabelPopoverUrl($page, "[...params.keys()].some((key) => key.startsWith('labels'))");

    $page->assertScript('document.querySelector("[data-testid=posts-label-count]")?.innerText', '2')
        ->click('@posts-label-clear');
    waitForLabelPopoverUrl($page, "!params.has('untagged') && ![...params.keys()].some((key) => key.startsWith('labels'))");

    $page->assertScript('new URLSearchParams(location.search).has("untagged")', false)
        ->assertScript('[...new URLSearchParams(location.search).keys()].some((key) => key.startsWith("labels"))', false)
        ->assertMissing('@posts-label-count')
        ->assertNoJavaScriptErrors();
});

test('the label filter offers to create a label when the workspace has none', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, '@posts-label-filter');
    $page->click('@posts-label-filter');
    waitFor($page, '@posts-label-empty');

    $page->assertMissing('@posts-label-untagged')
        ->assertMissing('@posts-label-settings')
        ->assertSeeIn('@posts-label-empty', __('posts.no_labels'))
        ->assertMissing('@posts-label-create')
        ->click('@posts-label-empty-create');
    waitFor($page, '@posts-label-new-name');

    expect($page->script(<<<'JS'
        (() => {
            const swatch = document.querySelector('[data-testid="posts-label-new-name"]').closest('form').querySelector('[data-testid="hex-color-swatch"]');
            const input = swatch.parentElement.querySelector('input');
            const s = swatch.getBoundingClientRect();
            const i = input.getBoundingClientRect();
            return [Math.round(s.width) === Math.round(s.height), Math.round(s.height) === Math.round(i.height), i.left - s.right >= 6];
        })()
    JS))->toBe([true, true, true]);

    $page->fill('@posts-label-new-name', 'Launch')
        ->click('@submit-posts-label-new');

    waitForDatabase($page, fn () => WorkspaceLabel::query()->where('workspace_id', $workspace->id)->where('name', 'Launch')->exists());

    expect(WorkspaceLabel::query()->where('workspace_id', $workspace->id)->where('name', 'Launch')->exists())->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

test('the label filter search ignores accents and hides untagged when it does not match', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $promo = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Promoção']);
    $launch = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Launch']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, '@posts-label-filter');
    $page->click('@posts-label-filter');
    waitFor($page, '@posts-label-untagged');

    $page->fill('@posts-label-search', 'promocao')
        ->assertVisible("@posts-label-option-{$promo->id}")
        ->assertMissing("@posts-label-option-{$launch->id}")
        ->assertMissing('@posts-label-untagged')
        ->fill('@posts-label-search', '')
        ->assertVisible('@posts-label-untagged')
        ->assertVisible("@posts-label-option-{$launch->id}")
        ->assertNoJavaScriptErrors();
});

test('each label in the filter shows as a chip in its own color', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $green = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Green', 'color' => '#16a34a']);
    $gray = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Gray', 'color' => '#d1d5db']);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']));
    waitFor($page, '@posts-label-filter');
    $page->click('@posts-label-filter');
    waitFor($page, "@posts-label-chip-{$green->id}");

    $chip = fn (WorkspaceLabel $label): array => $page->script(<<<JS
        (() => {
            const style = getComputedStyle(document.querySelector('[data-testid="posts-label-chip-{$label->id}"]'));
            return [style.backgroundColor, style.color];
        })()
    JS);

    expect($chip($green))->toBe(['rgb(22, 163, 74)', 'rgb(255, 255, 255)'])
        ->and($chip($gray))->toBe(['rgb(209, 213, 219)', 'rgb(10, 10, 10)']);
    $page->assertSeeIn("@posts-label-chip-{$green->id}", 'Green')
        ->assertNoJavaScriptErrors();
});
