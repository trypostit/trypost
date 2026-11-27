<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function labelMenuOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign']);

    return [$user, $label];
}

function assertLabelFilterSelected(mixed $page, string $testId, string $labelId): void
{
    waitFor($page, "@{$testId}-filter");
    $page->click("@{$testId}-filter");
    waitFor($page, "@{$testId}-checkbox-{$labelId}");
    $page->assertAttribute("@{$testId}-checkbox-{$labelId}", 'data-state', 'checked')
        ->assertNoJavaScriptErrors();
}

test('label row menu opens posts filtered by the label in a new tab', function () {
    [$user, $label] = labelMenuOwner();
    $this->actingAs($user);

    $page = visit(route('app.labels.index'));
    waitFor($page, "@label-menu-{$label->id}");
    $page->click("@label-menu-{$label->id}");
    waitFor($page, "@label-view-posts-{$label->id}");

    $links = $page->script(<<<JS
        (() => ['label-view-posts-{$label->id}'].map((id) => {
            const link = document.querySelector('[data-testid="' + id + '"]');
            return { href: decodeURIComponent(link.href), target: link.target, rel: link.rel };
        }))();
    JS);

    expect($links[0]['target'])->toBe('_blank')
        ->and($links[0]['rel'])->toContain('noopener')
        ->and($links[0]['href'])->toStartWith(route('app.posts.index'))
        ->and($links[0]['href'])->toContain("labels[]={$label->id}");

    $page->assertNoJavaScriptErrors();
});

test('the filtered posts page lands with the label selected', function () {
    [$user, $label] = labelMenuOwner();
    $this->actingAs($user);

    assertLabelFilterSelected(visit(route('app.posts.index', ['labels' => [$label->id]])), 'posts-label', $label->id);
});

test('the labels page without labels shows a centered illustration and a create button that is never cut off', function () {
    [$user, $label] = labelMenuOwner();
    $label->delete();
    $this->actingAs($user);

    $page = visit(route('app.labels.index'));
    waitFor($page, '@labels-empty');

    $page->assertVisible('@labels-empty-illustration')
        ->assertMissing('@header-title')
        ->assertMissing('@create-label-button')
        ->assertMissing('@header-search-input')
        ->assertSeeIn('@labels-empty', __('labels.no_labels_yet'));

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="labels-empty"]').closest('.overflow-y-auto');
            const box = scroller.getBoundingClientRect();
            const empty = document.querySelector('[data-testid="labels-empty"]').getBoundingClientRect();
            const vertical = Math.abs((empty.top + empty.height / 2) - (box.top + box.height / 2));
            const horizontal = Math.abs((empty.left + empty.width / 2) - (box.left + box.width / 2));
            return vertical <= 24 && horizontal <= 24;
        })()
    JS))->toBeTrue();

    $page->resize(375, 480);

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]');
            scroller.scrollTop = 0;
            const illustration = document.querySelector('[data-testid="labels-empty-illustration"]').getBoundingClientRect();
            const topVisible = illustration.top >= scroller.getBoundingClientRect().top;
            scroller.scrollTop = scroller.scrollHeight;
            const button = document.querySelector('[data-testid="labels-empty-create"]').getBoundingClientRect();
            return [topVisible, button.bottom <= scroller.getBoundingClientRect().bottom];
        })()
    JS))->toBe([true, true]);

    $page->click('@labels-empty-create');
    waitFor($page, '@create-label-sheet');
    $page->assertVisible('@create-label-sheet')->assertNoJavaScriptErrors();
});

test('a search without results keeps the header and search, and clearing it never flashes the no labels state', function () {
    [$user, $label] = labelMenuOwner();
    $this->actingAs($user);

    $page = visit(route('app.labels.index', ['search' => 'nothing-matches']));
    waitFor($page, '@empty-state');

    $page->assertVisible('@header-title')
        ->assertVisible('@create-label-button')
        ->assertVisible('@header-search-input')
        ->assertSeeIn('@empty-state', __('labels.no_search_results'))
        ->assertMissing('@labels-empty')
        ->assertMissing('@settings-centered')
        ->assertVisible('@labels-empty-illustration');

    $flashed = $page->script(<<<'JS'
        (async () => {
            let flashed = false;
            const observer = new MutationObserver(() => {
                if (document.querySelector('[data-testid="labels-empty"]')) flashed = true;
            });
            observer.observe(document.body, { childList: true, subtree: true });
            const input = document.querySelector('[data-testid="header-search-input"]');
            input.value = '';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            for (let i = 0; i < 60 && !document.querySelector('[data-testid^="label-row-"]'); i++) {
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
            observer.disconnect();
            return flashed;
        })()
    JS);

    expect($flashed)->toBeFalse();
    $page->assertVisible("@label-row-{$label->id}")->assertNoJavaScriptErrors();
});

test('a long labels list starts at the top and scrolls normally', function () {
    [$user, $label] = labelMenuOwner();
    WorkspaceLabel::factory()->count(30)->create(['workspace_id' => $label->workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.labels.index'));
    waitFor($page, '[data-testid^="label-row-"]');

    expect($page->script(<<<'JS'
        (() => {
            const scroller = document.querySelector('[data-testid="app-layout-scroller"]');
            const header = document.querySelector('[data-testid="header-title"]').getBoundingClientRect();
            const top = header.top - scroller.getBoundingClientRect().top;
            const scrolls = scroller.scrollHeight > scroller.clientHeight;
            scroller.scrollTop = scroller.scrollHeight;
            return [document.querySelector('[data-testid="settings-centered"]') === null, top < 120, scrolls, scroller.scrollTop > 0];
        })()
    JS))->toBe([true, true, true, true]);

    $page->assertNoJavaScriptErrors();
});

test('a label row shows its color, how many posts use it and its actions in one menu', function () {
    [$user, $label] = labelMenuOwner();
    $label->update(['color' => '#16a34a']);
    $post = Post::factory()->create(['workspace_id' => $label->workspace_id, 'user_id' => $user->id]);
    $label->posts()->attach($post->id);
    $this->actingAs($user);

    $page = visit(route('app.labels.index'));
    waitFor($page, "@label-row-{$label->id}");

    $page->assertSeeIn("@label-posts-count-{$label->id}", trans_choice('labels.meta.posts', 1, ['count' => 1]));

    expect($page->script("getComputedStyle(document.querySelector('[data-testid=\"label-swatch-{$label->id}\"]')).backgroundColor"))
        ->toBe('rgb(22, 163, 74)');

    $page->click("@label-menu-{$label->id}");
    waitFor($page, "@delete-label-{$label->id}");

    $page->assertVisible("@label-view-posts-{$label->id}")
        ->assertVisible("@label-view-insights-{$label->id}")
        ->assertMissing("@edit-label-{$label->id}")
        ->assertNoJavaScriptErrors();
});

test('only the cog on a label row opens its edit dialog, not the rest of the row, and the cog and menu say Settings and More actions', function () {
    [$user, $label] = labelMenuOwner();
    $this->actingAs($user);

    $page = visit(route('app.labels.index'));
    waitFor($page, "@label-settings-{$label->id}");

    $page->click("@label-posts-count-{$label->id}");
    $page->script('new Promise((resolve) => setTimeout(resolve, 400))');
    $page->assertMissing('@edit-label-dialog');

    $page->hover("@label-settings-{$label->id}");
    waitForText($page, "@label-settings-tooltip-{$label->id}", __('labels.actions.settings'));
    $page->hover('@header-title')->hover("@label-menu-{$label->id}");
    waitForText($page, "@label-menu-tooltip-{$label->id}", __('labels.actions.more'));

    $page->click("@label-settings-{$label->id}");
    waitForOpen($page, '@edit-label-dialog');

    $page->assertVisible('@edit-label-dialog')
        ->assertNoJavaScriptErrors();
});

test('label row menu opens insights filtered by the label in a new tab, with the label picked in the filter', function () {
    [$user, $label] = labelMenuOwner();
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.labels.index'));
    waitFor($page, "@label-menu-{$label->id}");
    $page->click("@label-menu-{$label->id}");
    waitFor($page, "@label-view-insights-{$label->id}");

    $link = $page->script(<<<JS
        (() => {
            const link = document.querySelector('[data-testid="label-view-insights-{$label->id}"]');
            return { href: decodeURIComponent(link.href), target: link.target, rel: link.rel };
        })()
    JS);

    expect($link['target'])->toBe('_blank')
        ->and($link['rel'])->toContain('noopener')
        ->and($link['href'])->toStartWith(route('app.insights'))
        ->and($link['href'])->toContain("labels[]={$label->id}");

    assertLabelFilterSelected(visit(route('app.insights', ['labels' => [$label->id]])), 'analytics-label', $label->id);
});

test('a label without posts says so and a label with thousands of posts shows its exact count', function () {
    [$user, $label] = labelMenuOwner();
    $empty = WorkspaceLabel::factory()->create(['workspace_id' => $label->workspace_id, 'name' => 'Empty']);
    $post = Post::factory()->create(['workspace_id' => $label->workspace_id, 'user_id' => $user->id]);
    $row = Arr::except((array) DB::table('posts')->where('id', $post->id)->first(), ['id']);
    $ids = collect(range(1, 1200))->map(fn (): string => (string) Str::uuid());
    $ids->chunk(200)->each(fn (Collection $chunk) => DB::table('posts')->insert($chunk->map(fn (string $id): array => [...$row, 'id' => $id])->all()));
    $ids->chunk(200)->each(fn (Collection $chunk) => DB::table('post_workspace_label')->insert($chunk->map(fn (string $id): array => ['post_id' => $id, 'workspace_label_id' => $label->id])->values()->all()));
    $this->actingAs($user);

    $page = visit(route('app.labels.index'));
    waitFor($page, "@label-posts-count-{$label->id}");

    $page->assertSeeIn("@label-posts-count-{$label->id}", trans_choice('labels.meta.posts', 1200, ['count' => 1200]))
        ->assertSeeIn("@label-posts-count-{$empty->id}", trans_choice('labels.meta.posts', 0, ['count' => 0]))
        ->assertNoJavaScriptErrors();
});
