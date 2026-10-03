<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

function waitForChannelReorderTestId(mixed $page, string $testId): void
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

function waitForChannelReorderDatabase(mixed $page, Closure $condition): void
{
    for ($attempt = 0; $attempt < 50 && ! $condition(); $attempt++) {
        $page->script('new Promise((resolve) => setTimeout(resolve, 100))');
    }
}

/**
 * @return array{0: User, 1: list<SocialAccount>}
 */
function channelReorderSetup(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $channels = collect(['first', 'second', 'third'])
        ->map(fn (string $name, int $index): SocialAccount => SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $workspace->id,
            'display_name' => ucfirst($name),
            'created_at' => now()->subDays(3 - $index),
        ]))
        ->all();

    return [$user->fresh(), $channels];
}

function channelReorderDomOrder(mixed $page, string $prefix, array $channels): array
{
    $ids = json_encode(array_map(fn (SocialAccount $channel): string => $channel->id, $channels));

    return $page->script(<<<JS
        (() => {
            const ids = {$ids};
            const rows = ids.map((id) => [id, document.querySelector('[data-testid="{$prefix}' + id + '"]')]);
            return rows
                .filter(([, el]) => el)
                .sort(([, a], [, b]) => (a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING ? -1 : 1))
                .map(([id]) => id);
        })()
    JS);
}

test('dragging a channel in the sidebar persists the order and the settings page shows it', function () {
    [$user, [$first, $second, $third]] = channelReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitForChannelReorderTestId($page, "sidebar-channel-{$third->id}");
    $page->hover("@sidebar-channel-row-{$third->id}");
    $page->drag("@sidebar-channel-handle-{$third->id}", "@sidebar-channel-row-{$first->id}");
    waitForChannelReorderDatabase($page, fn (): bool => $third->refresh()->position < $second->refresh()->position);

    expect($third->refresh()->position)->toBeLessThan($second->refresh()->position)
        ->and(channelReorderDomOrder($page, 'sidebar-channel-row-', [$first, $second, $third]))
        ->toBe($user->currentWorkspace->socialAccounts()->pluck('id')->all());
    $page->assertNoJavaScriptErrors();

    $settings = visit(route('app.workspace.channels'));
    waitForChannelReorderTestId($settings, "channel-row-{$third->id}");

    expect(channelReorderDomOrder($settings, 'channel-list-row-', [$first, $second, $third]))
        ->toBe($user->currentWorkspace->socialAccounts()->pluck('id')->all());
    $settings->assertNoJavaScriptErrors();
});

test('dragging a channel on the settings page persists the order', function () {
    [$user, [$first, $second, $third]] = channelReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelReorderTestId($page, "channel-handle-{$first->id}");
    $page->drag("@channel-handle-{$first->id}", "@channel-list-row-{$third->id}");
    waitForChannelReorderDatabase($page, fn (): bool => $first->refresh()->position > $second->refresh()->position);

    expect($first->refresh()->position)->toBeGreaterThan($second->refresh()->position)
        ->and(channelReorderDomOrder($page, 'channel-list-row-', [$first, $second, $third]))
        ->toBe($user->currentWorkspace->socialAccounts()->pluck('id')->all());
    $page->assertNoJavaScriptErrors();
});

test('channels can be moved with the keyboard and from the row menu', function () {
    [$user, [$first, $second, $third]] = channelReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelReorderTestId($page, "channel-handle-{$third->id}");
    $page->keys("@channel-handle-{$third->id}", 'ArrowUp');
    waitForChannelReorderDatabase($page, fn (): bool => $third->refresh()->position === 1);

    expect($user->currentWorkspace->socialAccounts()->pluck('id')->all())->toBe([$first->id, $third->id, $second->id])
        ->and($page->script('document.activeElement?.dataset.testid'))->toBe("channel-handle-{$third->id}");

    $page->click("@channel-menu-{$first->id}");
    waitForChannelReorderTestId($page, "channel-menu-move-down-{$first->id}");
    $page->click("@channel-menu-move-down-{$first->id}");
    waitForChannelReorderDatabase($page, fn (): bool => $first->refresh()->position === 1);

    expect($user->currentWorkspace->socialAccounts()->pluck('id')->all())->toBe([$third->id, $first->id, $second->id])
        ->and(channelReorderDomOrder($page, 'channel-list-row-', [$first, $second, $third]))->toBe([$third->id, $first->id, $second->id]);
    $page->assertNoJavaScriptErrors();
});

test('a stale reorder is rolled back and reported', function () {
    [$user, [$first, $second, $third]] = channelReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitForChannelReorderTestId($page, "channel-handle-{$second->id}");
    $late = SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]);
    $page->keys("@channel-handle-{$second->id}", 'ArrowUp');
    waitForChannelReorderTestId($page, 'channels-reorder-error-toast');

    $page->assertSeeIn('@channels-reorder-error-toast', __('channels.reorder.stale'));
    waitForChannelReorderTestId($page, "channel-row-{$late->id}");

    expect($user->currentWorkspace->socialAccounts()->pluck('id')->all())->toBe([$first->id, $second->id, $third->id, $late->id])
        ->and(channelReorderDomOrder($page, 'channel-list-row-', [$first, $second, $third, $late]))->toBe([$first->id, $second->id, $third->id, $late->id]);
    $page->assertNoJavaScriptErrors();
});
