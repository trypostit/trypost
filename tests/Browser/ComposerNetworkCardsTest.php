<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: Workspace}
 */
function networkCardsWorkspace(): array
{
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

function waitForNetworkCardsCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
                if (sheet?.getAttribute('data-state') === 'open'
                    && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                    && ({$condition})) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForNetworkCardsTestId(mixed $page, string $testId): void
{
    waitForNetworkCardsCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

function waitForNetworkCardsClosed(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

function openNetworkCardsComposer(mixed $test, User $user): mixed
{
    $test->actingAs($user);
    $page = visit(route('app.posts.create'));
    waitForNetworkCardsTestId($page, 'composer-add-account');

    return $page;
}

/**
 * @return array<string, mixed>
 */
function networkCardsAutosave(mixed $page, User $user, Workspace $workspace, string $condition): array
{
    $key = "trypost:composer:autosave:{$user->id}:{$workspace->id}";
    $snapshot = "JSON.parse(localStorage.getItem('{$key}') ?? 'null')";
    waitForNetworkCardsCondition($page, str_replace('snapshot', $snapshot, $condition));

    return (array) $page->script($snapshot);
}

function networkCardsCaptionCount(mixed $page): int
{
    return (int) $page->script('document.querySelectorAll(\'textarea[data-testid^="composer-caption-"]\').length');
}

/**
 * @return array<string, string|null>
 */
function networkCardsSavedContents(Workspace $workspace): array
{
    return Post::query()->where('workspace_id', $workspace->id)->with('postPlatforms')->get()
        ->mapWithKeys(fn (Post $post): array => [$post->postPlatforms->sole()->social_account_id => $post->content])
        ->all();
}

test('a second network keeps the shared step until customizing copies the text into every card', function () {
    [$user, $workspace] = networkCardsWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $page = openNetworkCardsComposer($this, $user);

    $page->fill('@composer-base-content', 'Draft for everyone')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->assertValue('@composer-base-content', 'Draft for everyone')
        ->assertMissing('@composer-customization')
        ->assertVisible('@composer-next');
    expect(networkCardsCaptionCount($page))->toBe(0);

    $page->click('@composer-next');
    waitForNetworkCardsTestId($page, "composer-caption-{$x->id}");

    $snapshot = networkCardsAutosave($page, $user, $workspace, "snapshot?.overrides?.['{$linkedin->id}']?.content !== undefined");
    expect(data_get($snapshot, "overrides.{$x->id}.content"))->toBe('Draft for everyone')
        ->and(data_get($snapshot, "overrides.{$linkedin->id}.content"))->toBe('Draft for everyone')
        ->and(networkCardsCaptionCount($page))->toBe(1);
    $page->assertMissing('@composer-base-content')
        ->assertMissing("@composer-expand-{$x->id}")
        ->assertValue("@composer-caption-{$x->id}", 'Draft for everyone')
        ->assertSeeIn("@composer-expand-{$linkedin->id}", 'Draft for everyone')
        ->fill("@composer-caption-{$x->id}", 'Only on X')
        ->assertSeeIn("@composer-expand-{$linkedin->id}", 'Draft for everyone')
        ->click("@composer-expand-{$linkedin->id}");
    waitForNetworkCardsTestId($page, "composer-caption-{$linkedin->id}");

    $page->assertValue("@composer-caption-{$linkedin->id}", 'Draft for everyone')
        ->fill("@composer-caption-{$linkedin->id}", 'Only on LinkedIn')
        ->assertSeeIn("@composer-expand-{$x->id}", 'Only on X')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft');
    waitForNetworkCardsClosed($page);

    expect(networkCardsSavedContents($workspace))->toEqual([
        $x->id => 'Only on X',
        $linkedin->id => 'Only on LinkedIn',
    ]);
});
test('a network added while customizing starts from the shared text', function () {
    [$user, $workspace] = networkCardsWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $facebook = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);
    $page = openNetworkCardsComposer($this, $user);

    $page->fill('@composer-base-content', 'Shared start')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->click('@composer-next')
        ->fill("@composer-caption-{$x->id}", 'Edited on X')
        ->click("@composer-expand-{$linkedin->id}")
        ->fill("@composer-caption-{$linkedin->id}", 'Edited on LinkedIn')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$facebook->id}");
    waitForNetworkCardsTestId($page, "composer-caption-{$facebook->id}");

    expect(networkCardsCaptionCount($page))->toBe(1);
    $page->assertValue("@composer-caption-{$facebook->id}", 'Shared start')
        ->assertSeeIn("@composer-expand-{$x->id}", 'Edited on X')
        ->assertSeeIn("@composer-expand-{$linkedin->id}", 'Edited on LinkedIn')
        ->assertNoJavaScriptErrors();
});
test('dropping back to one network keeps that network text as the post text', function () {
    [$user, $workspace] = networkCardsWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $page = openNetworkCardsComposer($this, $user);

    $page->fill('@composer-base-content', 'Shared start')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->click('@composer-next')
        ->click("@composer-expand-{$linkedin->id}")
        ->fill("@composer-caption-{$linkedin->id}", 'LinkedIn only')
        ->click("@composer-remove-account-{$x->id}");
    waitForNetworkCardsCondition($page, "!document.querySelector('[data-testid=\"composer-account-{$x->id}\"]')");

    $page->assertValue("@composer-caption-{$linkedin->id}", 'LinkedIn only')
        ->assertMissing("@composer-expand-{$linkedin->id}")
        ->assertMissing('@composer-back');
    $snapshot = networkCardsAutosave($page, $user, $workspace, "snapshot?.accountIds?.length === 1 && snapshot?.content === 'LinkedIn only'");
    expect(data_get($snapshot, "overrides.{$linkedin->id}"))->not->toHaveKey('content');

    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->assertValue('@composer-base-content', 'LinkedIn only')
        ->click('@composer-next');
    waitForNetworkCardsTestId($page, "composer-caption-{$x->id}");
    $page->assertValue("@composer-caption-{$x->id}", 'LinkedIn only')
        ->fill("@composer-caption-{$x->id}", 'X only')
        ->click("@composer-remove-account-{$linkedin->id}");
    waitForNetworkCardsCondition($page, "!document.querySelector('[data-testid=\"composer-account-{$linkedin->id}\"]')");

    $page->assertValue("@composer-caption-{$x->id}", 'X only')
        ->assertMissing("@composer-expand-{$x->id}")
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft');
    waitForNetworkCardsClosed($page);

    expect(networkCardsSavedContents($workspace))->toEqual([$x->id => 'X only']);
});
test('two pages of one network share one card and still save one post each', function () {
    [$user, $workspace] = networkCardsWorkspace();
    [$first, $second] = SocialAccount::factory()->facebook()->count(2)->create(['workspace_id' => $workspace->id])->all();
    $page = openNetworkCardsComposer($this, $user);

    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$first->id}")
        ->click("@composer-account-option-{$second->id}");
    waitForNetworkCardsTestId($page, "composer-caption-{$first->id}");

    $page->assertMissing("@composer-caption-{$second->id}")
        ->assertMissing("@composer-expand-{$first->id}")
        ->assertMissing("@composer-expand-{$second->id}")
        ->assertVisible("@composer-account-{$second->id}")
        ->fill("@composer-caption-{$first->id}", 'Both pages')
        ->assertSeeIn('@composer-save-draft', 'Save drafts')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft');
    waitForNetworkCardsClosed($page);

    expect(networkCardsSavedContents($workspace))->toEqual([
        $first->id => 'Both pages',
        $second->id => 'Both pages',
    ]);
});

test('cards and step one previews follow the channel list order and the preview follows the open card', function () {
    [$user, $workspace] = networkCardsWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $facebook = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);
    $page = openNetworkCardsComposer($this, $user);
    $order = <<<'JS'
        [...document.querySelectorAll('[data-testid="composer-customization"], [data-testid^="composer-expand-"]')]
            .map((node) => node.dataset.testid)
            .filter((testId) => testId !== 'composer-expand-dialog')
            .map((testId) => testId === 'composer-customization' ? 'open' : testId.replace('composer-expand-', ''))
    JS;

    $page->fill('@composer-base-content', 'Ordered')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$facebook->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->click("@composer-account-option-{$x->id}");
    expect($page->script('[...document.querySelectorAll(\'[data-testid="composer-preview-label"]\')].map((node) => node.textContent.trim())'))
        ->toBe(['X', 'LinkedIn', 'Facebook']);
    $page->assertSeeIn('@composer-preview-title', 'Post previews')
        ->click('@composer-next');
    waitForNetworkCardsTestId($page, "composer-caption-{$x->id}");

    expect($page->script($order))->toBe(['open', $linkedin->id, $facebook->id]);
    $page->assertSeeIn('@composer-preview-title', 'X preview')
        ->assertSeeIn('@composer-preview-frame', 'Ordered')
        ->click("@composer-expand-{$facebook->id}");
    waitForNetworkCardsTestId($page, "composer-caption-{$facebook->id}");

    expect($page->script($order))->toBe([$x->id, $linkedin->id, 'open']);
    $page->assertSeeIn('@composer-preview-title', 'Facebook preview')
        ->fill("@composer-caption-{$facebook->id}", '')
        ->assertVisible('@composer-empty-preview')
        ->assertNoJavaScriptErrors();
});
test('two pinterest accounts in one card keep their own boards', function () {
    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => Http::response(['items' => [
            ['id' => 'board_1', 'name' => 'Disney'],
            ['id' => 'board_2', 'name' => 'Social'],
        ]]),
        '*' => Http::response([], 200),
    ]);
    [$user, $workspace] = networkCardsWorkspace();
    [$first, $second] = SocialAccount::factory()->pinterest()->count(2)->create(['workspace_id' => $workspace->id])->all();
    $page = openNetworkCardsComposer($this, $user);

    $page->fill('@composer-base-content', 'Pinned twice')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$first->id}")
        ->click("@composer-account-option-{$second->id}");
    waitForNetworkCardsTestId($page, "composer-account-settings-{$second->id}");
    $page->assertVisible("@composer-account-settings-{$first->id}");

    $page->script("document.querySelector('[data-testid=\"composer-account-settings-{$second->id}\"] [data-testid=\"pinterest-board-trigger\"]').click()");
    waitForNetworkCardsTestId($page, 'pinterest-board-option-board_2');
    $page->click('@pinterest-board-option-board_2');
    waitForNetworkCardsCondition($page, "!document.querySelector('[data-testid=\"pinterest-board-picker\"]')");
    $page->assertNoJavaScriptErrors()->click('@composer-save-draft');
    waitForNetworkCardsClosed($page);

    $boards = Post::query()->where('workspace_id', $workspace->id)->with('postPlatforms')->get()
        ->mapWithKeys(fn (Post $post): array => [$post->postPlatforms->sole()->social_account_id => data_get($post->postPlatforms->sole()->meta, 'board_id')]);
    expect($boards[$second->id])->toBe('board_2')
        ->and($boards[$first->id])->not->toBe('board_2');
});

test('a story card hides its caption and keeps the text', function () {
    [$user, $workspace] = networkCardsWorkspace();
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $page = openNetworkCardsComposer($this, $user);

    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$instagram->id}")
        ->fill("@composer-caption-{$instagram->id}", 'Kept for later')
        ->click("@composer-type-{$instagram->id}-instagram_story");
    waitForNetworkCardsCondition($page, "!document.querySelector('[data-testid=\"composer-caption-{$instagram->id}\"]')");

    $page->assertMissing("@composer-char-count-{$instagram->id}")
        ->click("@composer-type-{$instagram->id}-instagram_feed");
    waitForNetworkCardsTestId($page, "composer-caption-{$instagram->id}");
    $page->assertValue("@composer-caption-{$instagram->id}", 'Kept for later')
        ->assertNoJavaScriptErrors();
});

test('going back and saving a draft saves the shared text on every network', function () {
    [$user, $workspace] = networkCardsWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $page = openNetworkCardsComposer($this, $user);

    $page->fill('@composer-base-content', 'Shared text')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->click('@composer-next')
        ->fill("@composer-caption-{$x->id}", 'Only on X')
        ->click('@composer-back')
        ->click('@composer-back-confirm-go');
    waitForNetworkCardsTestId($page, 'composer-base-content');

    $page->assertValue('@composer-base-content', 'Shared text')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft');
    waitForNetworkCardsClosed($page);

    expect(networkCardsSavedContents($workspace))->toEqual([
        $x->id => 'Shared text',
        $linkedin->id => 'Shared text',
    ]);
});

test('going back drops a thread started on a network card', function () {
    [$user, $workspace] = networkCardsWorkspace();
    $bluesky = SocialAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $page = openNetworkCardsComposer($this, $user);

    $page->fill('@composer-base-content', 'Shared text')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$bluesky->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->click("@composer-account-{$bluesky->id}")
        ->assertMissing('@thread-start')
        ->click('@composer-next');
    waitForNetworkCardsTestId($page, 'thread-start');
    $page->click('@thread-start');
    waitForNetworkCardsTestId($page, 'thread-reply-0');
    $page->fill('@thread-reply-0', 'A reply')
        ->click('@composer-back')
        ->click('@composer-back-confirm-go');
    waitForNetworkCardsTestId($page, 'composer-next');
    $page->click('@composer-next');
    waitForNetworkCardsTestId($page, 'thread-start');

    $page->assertMissing('@thread-replies')
        ->assertValue("@composer-caption-{$bluesky->id}", 'Shared text')
        ->assertNoJavaScriptErrors();
});
