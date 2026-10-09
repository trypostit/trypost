<?php

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Theme;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\AnalyticsAccountDailySnapshot;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

function waitForDarkModeSurfacesTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        new Promise((resolve, reject) => {
            const started = Date.now();
            const check = () => {
                const element = document.querySelector('[data-testid="{$testId}"]');
                if (element && element.getBoundingClientRect().height > 0) {
                    resolve(true);
                } else if (Date.now() - started > 10000) {
                    reject(new Error('Timed out waiting for {$testId}'));
                } else {
                    requestAnimationFrame(check);
                }
            };
            check();
        })
    JS);
}

test('in dark mode a dialog is a raised surface, lighter than the page behind it', function () {
    $user = User::factory()->create(['theme' => Theme::Dark]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $post = Post::factory()->forAccount($account, ContentType::XPost)->create(['user_id' => $user->id, 'content' => 'Surface check']);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post))->resize(1440, 900);
    waitForDarkModeSurfacesTestId($page, 'post-composer-dialog');

    $surfaces = $page->script(<<<'JS'
        (() => {
            const luminance = (element) => {
                const [r, g, b] = getComputedStyle(element).backgroundColor.match(/\d+/g).map(Number);
                return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            };
            const dialog = document.querySelector('[data-slot="dialog-content"]');
            return { page: luminance(document.documentElement), dialog: luminance(dialog) };
        })()
    JS);

    expect($surfaces['dialog'] - $surfaces['page'])->toBeGreaterThanOrEqual(8);
    $page->assertNoJavaScriptErrors();
});

test('in dark mode the new menu icons stand out from their tiles', function () {
    $user = User::factory()->create(['theme' => Theme::Dark]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1440, 900);
    waitForDarkModeSurfacesTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForDarkModeSurfacesTestId($page, 'sidebar-new-idea-icon');

    $contrast = $page->script(<<<'JS'
        (() => {
            const luminance = (color) => {
                const [r, g, b] = color.match(/\d+/g).map(Number);
                return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            };
            return ['sidebar-new-post-icon', 'sidebar-new-idea-icon'].map((id) => {
                const tile = document.querySelector(`[data-testid="${id}"]`);
                const icon = tile.querySelector('svg');
                return Math.round(Math.abs(luminance(getComputedStyle(tile).backgroundColor) - luminance(getComputedStyle(icon).color)));
            });
        })()
    JS);

    expect($contrast[0])->toBeGreaterThan(80)
        ->and($contrast[1])->toBeGreaterThan(80);
    $page->assertNoJavaScriptErrors();
});

test('in dark mode the new button hovers one step lighter, not to the palest violet', function () {
    $user = User::factory()->create(['theme' => Theme::Dark]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'))->resize(1440, 900);
    waitForDarkModeSurfacesTestId($page, 'sidebar-new');
    $page->click('@sidebar-new');
    waitForDarkModeSurfacesTestId($page, 'sidebar-new-menu');

    $colors = $page->script(<<<'JS'
        new Promise((done) => setTimeout(() => {
            const probe = document.createElement('div');
            document.body.append(probe);
            const resolve = (token) => {
                probe.style.backgroundColor = `var(${token})`;
                return getComputedStyle(probe).backgroundColor;
            };
            const result = {
                open: getComputedStyle(document.querySelector('[data-testid="sidebar-new"]')).backgroundColor,
                primary: resolve('--primary'),
                palest: resolve('--primary-text-hover'),
            };
            probe.remove();
            done(result);
        }, 500))
    JS);

    expect($colors['open'])->toBe($colors['primary'])
        ->and($colors['open'])->not->toBe($colors['palest']);
    $page->assertNoJavaScriptErrors();
});

test('in dark mode the idea board columns and cards step up from the page', function () {
    $user = User::factory()->create(['theme' => Theme::Dark]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $stage = IdeaStage::factory()->create(['workspace_id' => $workspace->id, 'name' => 'To Do']);
    $idea = Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => $stage->id, 'title' => 'Board idea']);
    $this->actingAs($user);

    $page = visit(route('app.create.ideas.index'))->resize(1440, 900);
    waitForDarkModeSurfacesTestId($page, "idea-card-{$idea->id}");

    $surfaces = $page->script(<<<JS
        (() => {
            const luminance = (element) => {
                const [r, g, b] = getComputedStyle(element).backgroundColor.match(/\\d+/g).map(Number);
                return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            };
            const card = document.querySelector('[data-testid="idea-card-{$idea->id}"]');
            let column = card.parentElement;
            while (getComputedStyle(column).backgroundColor === 'rgba(0, 0, 0, 0)') {
                column = column.parentElement;
            }
            const panel = document.querySelector('[data-slot="sidebar-inset"]');
            return { panel: luminance(panel), column: luminance(column), card: luminance(card) };
        })()
    JS);

    expect($surfaces['column'] - $surfaces['panel'])->toBeGreaterThanOrEqual(4)
        ->and($surfaces['card'] - $surfaces['column'])->toBeGreaterThanOrEqual(6);
    $page->assertNoJavaScriptErrors();
});

test('in dark mode the insights sections sit below the page panel and their cards step up from them', function () {
    Queue::fake([BootstrapAccountAnalytics::class, CollectAccountDailySnapshot::class]);
    $user = User::factory()->create(['theme' => Theme::Dark]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    AnalyticsAccountDailySnapshot::factory()->create([
        'workspace_id' => $workspace->id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => Platform::Instagram,
        'network' => Platform::Instagram->network(),
        'platform_user_id' => $account->platform_user_id,
        'account_username' => $account->username,
        'date' => now()->subDays(3)->toDateString(),
        'followers_count' => 120,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.insights'))->resize(1440, 900);
    waitForDarkModeSurfacesTestId($page, 'analytics-summary-followers');

    $surfaces = $page->script(<<<'JS'
        (() => {
            const luminance = (element) => {
                const [r, g, b] = getComputedStyle(element).backgroundColor.match(/\d+/g).map(Number);
                return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            };
            const card = document.querySelector('[data-testid="analytics-summary-followers"]');
            return {
                panel: luminance(document.querySelector('[data-slot="sidebar-inset"]')),
                section: luminance(card.closest('[data-testid="analytics-section"]')),
                card: luminance(card),
            };
        })()
    JS);

    expect(abs($surfaces['section'] - $surfaces['panel']))->toBeGreaterThanOrEqual(4)
        ->and($surfaces['card'] - $surfaces['section'])->toBeGreaterThanOrEqual(4);
    $page->assertNoJavaScriptErrors();
});

test('in dark mode the connect channel dialog keeps its 8px frame around the inner panel, like light', function () {
    $user = User::factory()->create(['theme' => Theme::Dark]);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.repurposes.index'))->resize(1440, 900);
    waitForDarkModeSurfacesTestId($page, 'repurposes-empty-connect');
    $page->click('@repurposes-empty-connect');
    waitForDarkModeSurfacesTestId($page, 'connect-channel-telegram');

    $layers = $page->script(<<<'JS'
        (() => {
            const luminance = (element) => {
                const [r, g, b] = getComputedStyle(element).backgroundColor.match(/\d+/g).map(Number);
                return 0.2126 * r + 0.7152 * g + 0.0722 * b;
            };
            const card = document.querySelector('[data-testid="connect-channel-telegram"]');
            let panel = card.parentElement;
            while (getComputedStyle(panel).backgroundColor === 'rgba(0, 0, 0, 0)') {
                panel = panel.parentElement;
            }
            const frame = document.querySelector('[data-testid="connect-channel-dialog"]');
            const style = getComputedStyle(frame);
            const [r, g, b] = style.borderTopColor.match(/\d+/g).map(Number);
            return {
                width: style.borderTopWidth,
                frame: 0.2126 * r + 0.7152 * g + 0.0722 * b,
                panel: luminance(panel),
            };
        })()
    JS);

    expect($layers['width'])->toBe('8px')
        ->and($layers['frame'] - $layers['panel'])->toBeGreaterThanOrEqual(10);
    $page->assertNoJavaScriptErrors();
});
