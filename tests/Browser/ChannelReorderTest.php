<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

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

test('the channels settings page lists channels without reordering them', function () {
    [$user, [$first, $second, $third]] = channelReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.workspace.channels'));
    waitFor($page, "@channel-list-row-{$third->id}");
    $page->click("@channel-menu-{$second->id}");
    waitFor($page, "@channel-disconnect-{$second->id}");

    expect($page->script('document.querySelectorAll(\'[data-testid^="channel-handle-"], [data-testid^="channel-menu-move-"], [data-sortable-channel]\').length'))->toBe(0)
        ->and(channelReorderDomOrder($page, 'channel-list-row-', [$first, $second, $third]))->toBe([$first->id, $second->id, $third->id]);
    $page->assertNoJavaScriptErrors();
});

test('a sidebar channel can be moved with the keyboard', function () {
    [$user, [$first, $second, $third]] = channelReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitFor($page, "@sidebar-channel-row-{$third->id}");
    $page->hover("@sidebar-channel-row-{$third->id}");
    $page->keys("@sidebar-channel-handle-{$third->id}", 'ArrowUp');
    waitForDatabase($page, fn (): bool => $third->refresh()->position === 1);

    expect($user->currentWorkspace->socialAccounts()->pluck('id')->all())->toBe([$first->id, $third->id, $second->id])
        ->and($page->script('document.activeElement?.dataset.testid'))->toBe("sidebar-channel-handle-{$third->id}")
        ->and(channelReorderDomOrder($page, 'sidebar-channel-row-', [$first, $second, $third]))->toBe([$first->id, $third->id, $second->id]);
    $page->assertNoJavaScriptErrors();
});

test('a stale reorder is rolled back and reported', function () {
    [$user, [$first, $second, $third]] = channelReorderSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    waitFor($page, "@sidebar-channel-row-{$second->id}");
    $late = SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]);
    $page->hover("@sidebar-channel-row-{$second->id}");
    $page->keys("@sidebar-channel-handle-{$second->id}", 'ArrowUp');
    waitFor($page, '@channels-reorder-error-toast');

    $page->assertSeeIn('@channels-reorder-error-toast', __('channels.reorder.stale'));
    waitFor($page, "@sidebar-channel-row-{$late->id}");

    expect($user->currentWorkspace->socialAccounts()->pluck('id')->all())->toBe([$first->id, $second->id, $third->id, $late->id])
        ->and(channelReorderDomOrder($page, 'sidebar-channel-row-', [$first, $second, $third, $late]))->toBe([$first->id, $second->id, $third->id, $late->id]);
    $page->assertNoJavaScriptErrors();
});
