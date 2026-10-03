<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Pest\Browser\Playwright\Client;

function waitForSidebarReorderCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if ({$condition}) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function waitForSidebarReorderTestId(mixed $page, string $testId): void
{
    waitForSidebarReorderCondition($page, "(() => { const el = document.querySelector('[data-testid=\"{$testId}\"]'); return el && el.getBoundingClientRect().height > 0; })()");
}

function sidebarReorderMouse(mixed $page, string $method, array $params = []): void
{
    $page->script('true');
    $awaitable = property_exists($page, 'waitablePage') ? (fn () => $this->waitablePage)->call($page) : $page;
    $playwrightPage = (fn () => $this->page)->call($awaitable);
    $guid = (fn () => $this->guid)->call($playwrightPage);

    iterator_to_array(Client::instance()->execute($guid, $method, $params));
}

/**
 * @return array{x: float, y: float}
 */
function sidebarReorderPoint(mixed $page, string $testId, float $yRatio = 0.5): array
{
    return $page->script(<<<JS
        (() => {
            const rect = document.querySelector('[data-testid="{$testId}"]').getBoundingClientRect();
            return { x: rect.left + rect.width / 2, y: rect.top + rect.height * {$yRatio} };
        })()
    JS);
}

function sidebarReorderPickUp(mixed $page, SocialAccount $channel): void
{
    $page->hover("@sidebar-channel-row-{$channel->id}");
    $handle = sidebarReorderPoint($page, "sidebar-channel-handle-{$channel->id}");
    sidebarReorderMouse($page, 'mouseMove', ['x' => $handle['x'], 'y' => $handle['y']]);
    sidebarReorderMouse($page, 'mouseDown', ['button' => 'left', 'clickCount' => 1]);
    sidebarReorderMouse($page, 'mouseMove', ['x' => $handle['x'] + 8, 'y' => $handle['y'] + 2, 'steps' => 4]);
}

function sidebarReorderMoveTo(mixed $page, string $testId, float $yRatio): void
{
    $target = sidebarReorderPoint($page, $testId, $yRatio);
    sidebarReorderMouse($page, 'mouseMove', ['x' => $target['x'], 'y' => $target['y'], 'steps' => 60]);
}

function sidebarReorderDrop(mixed $page): void
{
    sidebarReorderMouse($page, 'mouseUp', ['button' => 'left', 'clickCount' => 1]);
}

function sidebarReorderDrag(mixed $page, SocialAccount $channel, SocialAccount $target, float $yRatio): void
{
    sidebarReorderPickUp($page, $channel);
    sidebarReorderMoveTo($page, "sidebar-channel-row-{$target->id}", $yRatio);
    sidebarReorderDrop($page);
}

/**
 * @return array{0: User, 1: list<SocialAccount>}
 */
function sidebarReorderSetup(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $channels = collect(['alpha', 'bravo', 'charlie', 'delta'])
        ->map(fn (string $name, int $index): SocialAccount => SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $workspace->id,
            'display_name' => ucfirst($name),
            'position' => $index,
        ]))
        ->all();

    return [$user->fresh(), $channels];
}

function sidebarReorderDomOrder(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="sidebar-channels-list"] > [data-testid^="sidebar-channel-row-"]')]
            .map((row) => row.dataset.testid.replace('sidebar-channel-row-', ''))
    JS);
}

function sidebarReorderSlots(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelector('[data-testid="sidebar-channels-list"]').children]
            .filter((child) => child.offsetHeight > 0)
            .map((child) => child.dataset.testid === 'sidebar-channel-placeholder' ? 'placeholder' : child.dataset.testid.replace('sidebar-channel-row-', ''))
    JS);
}

function sidebarReorderPause(mixed $page, int $milliseconds = 400): void
{
    $page->script("new Promise((resolve) => setTimeout(resolve, {$milliseconds}))");
}

function sidebarReorderDbOrder(User $user): array
{
    return $user->currentWorkspace->socialAccounts()->pluck('id')->all();
}

function sidebarReorderSettle(mixed $page, User $user, array $expected): void
{
    $ids = json_encode($expected);

    for ($attempt = 0; $attempt < 50 && sidebarReorderDbOrder($user) !== $expected; $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }

    waitForSidebarReorderCondition($page, "JSON.stringify([...document.querySelectorAll('[data-testid=\"sidebar-channels-list\"] > [data-testid^=\"sidebar-channel-row-\"]')].map((row) => row.dataset.testid.replace('sidebar-channel-row-', ''))) === JSON.stringify({$ids}) && !document.querySelector('[data-testid=\"sidebar-channel-placeholder\"]')");
}

test('the second channel can be dragged to the top twice in a row', function () {
    [$user, [$alpha, $bravo, $charlie, $delta]] = sidebarReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarReorderTestId($page, "sidebar-channel-{$delta->id}");

    sidebarReorderDrag($page, $bravo, $alpha, 0.25);
    $expected = [$bravo->id, $alpha->id, $charlie->id, $delta->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected)
        ->and(sidebarReorderDomOrder($page))->toBe($expected);

    sidebarReorderDrag($page, $alpha, $bravo, 0.25);
    $expected = [$alpha->id, $bravo->id, $charlie->id, $delta->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected)
        ->and(sidebarReorderDomOrder($page))->toBe($expected);

    $page->assertNoJavaScriptErrors();
});

test('the first channel can go last and the last channel can go first', function () {
    [$user, [$alpha, $bravo, $charlie, $delta]] = sidebarReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarReorderTestId($page, "sidebar-channel-{$delta->id}");

    sidebarReorderDrag($page, $alpha, $delta, 0.9);
    $expected = [$bravo->id, $charlie->id, $delta->id, $alpha->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected)
        ->and(sidebarReorderDomOrder($page))->toBe($expected);

    sidebarReorderDrag($page, $alpha, $bravo, 0.1);
    $expected = [$alpha->id, $bravo->id, $charlie->id, $delta->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected)
        ->and(sidebarReorderDomOrder($page))->toBe($expected);

    sidebarReorderDrag($page, $delta, $alpha, 0.1);
    $expected = [$delta->id, $alpha->id, $bravo->id, $charlie->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected)
        ->and(sidebarReorderDomOrder($page))->toBe($expected);

    $page->assertNoJavaScriptErrors();
});

test('a placeholder opens where the channel would land and the drop uses it', function () {
    [$user, [$alpha, $bravo, $charlie, $delta]] = sidebarReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForSidebarReorderTestId($page, "sidebar-channel-{$delta->id}");
    $height = $page->script("document.querySelector('[data-testid=\"sidebar-channel-row-{$charlie->id}\"]').offsetHeight");

    $page->script(<<<'JS'
        (() => {
            window.__dragImages = [];
            const original = DataTransfer.prototype.setDragImage;
            DataTransfer.prototype.setDragImage = function (image, x, y) {
                window.__dragImages.push(image instanceof HTMLImageElement ? image.src.slice(0, 10) : image.tagName);
                return original.call(this, image, x, y);
            };
        })()
    JS);

    sidebarReorderPickUp($page, $charlie);
    waitForSidebarReorderTestId($page, 'sidebar-channel-placeholder');

    expect($page->script('window.__dragImages'))->toBe(['data:image']);
    expect($page->script("document.querySelector('[data-testid=\"sidebar-channel-placeholder-preview\"]')?.textContent"))->toEndWith($charlie->display_name ?: $charlie->username);

    expect(sidebarReorderSlots($page))->toBe([$alpha->id, $bravo->id, 'placeholder', $delta->id])
        ->and($page->script("document.querySelector('[data-testid=\"sidebar-channel-placeholder\"]').offsetHeight"))->toBe($height);

    sidebarReorderMoveTo($page, "sidebar-channel-row-{$alpha->id}", 0.25);
    sidebarReorderPause($page);

    expect(sidebarReorderSlots($page))->toBe(['placeholder', $alpha->id, $bravo->id, $delta->id]);

    sidebarReorderMoveTo($page, 'sidebar-channels-list', 0.98);
    sidebarReorderPause($page);

    expect(sidebarReorderSlots($page))->toBe([$alpha->id, $bravo->id, $delta->id, 'placeholder']);

    sidebarReorderDrop($page);
    $expected = [$alpha->id, $bravo->id, $delta->id, $charlie->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected)
        ->and(sidebarReorderSlots($page))->toBe($expected);

    $page->assertNoJavaScriptErrors();
});

test('escape cancels a drag and restores the order', function () {
    [$user, [$alpha, $bravo, $charlie, $delta]] = sidebarReorderSetup();
    $this->actingAs($user);
    $original = [$alpha->id, $bravo->id, $charlie->id, $delta->id];

    $page = visit(route('app.posts.index'));
    waitForSidebarReorderTestId($page, "sidebar-channel-{$delta->id}");

    sidebarReorderPickUp($page, $delta);
    sidebarReorderMoveTo($page, "sidebar-channel-row-{$alpha->id}", 0.25);
    sidebarReorderPause($page);

    expect(sidebarReorderSlots($page))->toBe(['placeholder', $alpha->id, $bravo->id, $charlie->id]);

    $page->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");
    sidebarReorderPause($page, 100);

    expect(sidebarReorderSlots($page))->toBe($original);

    sidebarReorderDrop($page);
    sidebarReorderPause($page, 800);

    expect(sidebarReorderDbOrder($user))->toBe($original)
        ->and(sidebarReorderSlots($page))->toBe($original);

    $page->assertNoJavaScriptErrors();
});

test('dropping outside the list cancels the drag', function () {
    [$user, [$alpha, $bravo, $charlie, $delta]] = sidebarReorderSetup();
    $this->actingAs($user);
    $original = [$alpha->id, $bravo->id, $charlie->id, $delta->id];

    $page = visit(route('app.posts.index'))->resize(1280, 800);
    waitForSidebarReorderTestId($page, "sidebar-channel-{$delta->id}");

    sidebarReorderPickUp($page, $bravo);
    sidebarReorderMoveTo($page, "sidebar-channel-row-{$delta->id}", 0.75);
    sidebarReorderPause($page);

    expect(sidebarReorderSlots($page))->toBe([$alpha->id, $charlie->id, $delta->id, 'placeholder']);

    sidebarReorderMouse($page, 'mouseMove', ['x' => 900, 'y' => 400, 'steps' => 8]);
    sidebarReorderPause($page);

    expect(sidebarReorderSlots($page))->toBe([$alpha->id, 'placeholder', $charlie->id, $delta->id]);

    sidebarReorderDrop($page);
    sidebarReorderPause($page, 800);

    expect(sidebarReorderDbOrder($user))->toBe($original)
        ->and(sidebarReorderSlots($page))->toBe($original);

    $page->assertNoJavaScriptErrors();
});

test('an expanded channel submenu does not shift the drop position', function () {
    [$user, [$alpha, $bravo, $charlie, $delta]] = sidebarReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.channels.publish', $alpha));
    waitForSidebarReorderTestId($page, "sidebar-channel-{$alpha->id}-insights");

    sidebarReorderPickUp($page, $delta);
    sidebarReorderMoveTo($page, "sidebar-channel-row-{$alpha->id}", 0.75);
    sidebarReorderPause($page);

    expect(sidebarReorderSlots($page))->toBe([$alpha->id, 'placeholder', $bravo->id, $charlie->id]);

    sidebarReorderDrop($page);
    $expected = [$alpha->id, $delta->id, $bravo->id, $charlie->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected);

    sidebarReorderDrag($page, $alpha, $charlie, 0.75);
    $expected = [$delta->id, $bravo->id, $charlie->id, $alpha->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected)
        ->and(sidebarReorderDomOrder($page))->toBe($expected);

    $page->assertNoJavaScriptErrors();
});

test('the list scrolls while a channel is dragged near its bottom edge', function () {
    [$user, [$alpha]] = sidebarReorderSetup();
    SocialAccount::factory()->linkedin()->count(16)->sequence(fn ($sequence): array => ['position' => $sequence->index + 4])->create(['workspace_id' => $user->current_workspace_id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1280, 700);
    waitForSidebarReorderTestId($page, "sidebar-channel-{$alpha->id}");

    expect($page->script("document.querySelector('[data-testid=\"sidebar-channels-list\"]').scrollTop"))->toBe(0);

    sidebarReorderPickUp($page, $alpha);
    sidebarReorderMoveTo($page, 'sidebar-channels-list', 0.97);
    waitForSidebarReorderCondition($page, '(() => { const list = document.querySelector(\'[data-testid="sidebar-channels-list"]\'); return list.scrollTop + list.clientHeight >= list.scrollHeight - 1; })()');

    $list = $page->script(<<<'JS'
        (() => {
            const list = document.querySelector('[data-testid="sidebar-channels-list"]');
            return { scrollTop: list.scrollTop, atBottom: list.scrollTop + list.clientHeight >= list.scrollHeight - 1 };
        })()
    JS);

    expect($list['scrollTop'])->toBeGreaterThan(0)
        ->and($list['atBottom'])->toBeTrue()
        ->and(sidebarReorderSlots($page)[19])->toBe('placeholder');

    sidebarReorderDrop($page);
    $ids = $user->currentWorkspace->socialAccounts()->pluck('id')->all();
    $expected = [...array_values(array_diff($ids, [$alpha->id])), $alpha->id];
    sidebarReorderSettle($page, $user, $expected);

    expect(sidebarReorderDbOrder($user))->toBe($expected);

    $page->assertNoJavaScriptErrors();
});
