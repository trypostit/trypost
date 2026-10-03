<?php

declare(strict_types=1);

use App\Enums\User\TimeFormat;
use App\Models\Idea;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForComposerAutosaveTestId(mixed $page, string $testId): void
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

function waitForComposerAutosaveCondition(mixed $page, string $condition): void
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

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount}
 */
function composerAutosaveWorkspace(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    return [$user, $workspace, $account];
}

function composerAutosaveKeyFor(User $user, Workspace $workspace): string
{
    return "trypost:composer:autosave:{$user->id}:{$workspace->id}";
}

function openComposerForAutosave(mixed $page): void
{
    waitForComposerAutosaveTestId($page, 'posts-new-post');
    $page->click('@posts-new-post');
    waitForComposerAutosaveTestId($page, 'composer-base-content');
}

function closeComposerForAutosave(mixed $page): void
{
    $page->click('@composer-close');
    waitForComposerAutosaveCondition($page, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');
}

/**
 * @param  array<string, mixed>  $snapshot
 */
function seedComposerAutosave(mixed $page, string $key, array $snapshot): void
{
    $json = json_encode([
        'version' => 1,
        'savedAt' => now()->toISOString(),
        'content' => '',
        'overrides' => [],
        'accountIds' => [],
        'media' => [],
        'labelIds' => [],
        'scheduleMode' => null,
        'scheduledAt' => null,
        ...$snapshot,
    ]);
    $page->script("localStorage.setItem('{$key}', ".json_encode($json).')');
}

function writeUnfinishedPost(mixed $page, SocialAccount $account, string $content): void
{
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->fill("@composer-caption-{$account->id}", $content);
}

test('an unfinished post is offered back and resume restores its text and channel', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openComposerForAutosave($page);
    writeUnfinishedPost($page, $account, 'Half written idea');
    closeComposerForAutosave($page);

    $key = composerAutosaveKeyFor($user, $workspace);
    expect($page->script("JSON.parse(localStorage.getItem('{$key}')).content"))->toBe('Half written idea');

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->assertSeeIn('@composer-resume-preview', 'Half written idea')
        ->assertVisible('@composer-resume-accounts')
        ->assertSeeIn('@composer-resume-discard', 'Discard')
        ->assertSeeIn('@composer-resume-confirm', 'Resume post')
        ->click('@composer-resume-confirm');
    waitForComposerAutosaveCondition($page, '!document.querySelector(\'[data-testid="composer-resume-dialog"]\')');

    $page->assertValue("@composer-caption-{$account->id}", 'Half written idea')
        ->assertVisible("@composer-account-{$account->id}")
        ->assertNoJavaScriptErrors();
});

test('discarding the unfinished post clears it so the next open does not ask again', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openComposerForAutosave($page);
    writeUnfinishedPost($page, $account, 'Throw this away');
    closeComposerForAutosave($page);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-discard');
    waitForComposerAutosaveCondition($page, '!document.querySelector(\'[data-testid="composer-resume-dialog"]\')');
    $page->assertValue('@composer-base-content', '');

    $key = composerAutosaveKeyFor($user, $workspace);
    expect($page->script("localStorage.getItem('{$key}')"))->toBeNull();

    closeComposerForAutosave($page);
    openComposerForAutosave($page);
    $page->assertMissing('@composer-resume-dialog')
        ->assertNoJavaScriptErrors();
});

test('saving a draft clears the unfinished post', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openComposerForAutosave($page);
    writeUnfinishedPost($page, $account, 'Saved for real');
    $page->click('@composer-save-draft');
    waitForComposerAutosaveCondition($page, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');

    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(1);
    $key = composerAutosaveKeyFor($user, $workspace);
    expect($page->script("localStorage.getItem('{$key}')"))->toBeNull();

    openComposerForAutosave($page);
    $page->assertMissing('@composer-resume-dialog')
        ->assertValue('@composer-base-content', '')
        ->assertNoJavaScriptErrors();
});

test('editing an existing post never offers the unfinished post', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $post = Post::factory()->draft()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Existing post',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openComposerForAutosave($page);
    writeUnfinishedPost($page, $account, 'Unfinished elsewhere');
    closeComposerForAutosave($page);

    $edit = $page->navigate(route('app.posts.edit', $post));
    waitForComposerAutosaveTestId($edit, 'composer-base-content');
    $edit->assertValue('@composer-base-content', 'Existing post')
        ->assertMissing('@composer-resume-dialog');

    $key = composerAutosaveKeyFor($user, $workspace);
    expect($edit->script("JSON.parse(localStorage.getItem('{$key}')).content"))->toBe('Unfinished elsewhere');
    $edit->assertNoJavaScriptErrors();
});

test('resume drops expired temporary uploads and keeps media owned by an idea or post', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'With media',
        'accountIds' => [$account->id],
        'media' => [
            ['id' => 'expired-upload', 'upload_token' => 'expired-token', 'created_at' => now()->subHours(25)->toISOString(), 'url' => '/images/expired.png', 'type' => 'image', 'mime_type' => 'image/png'],
            ['id' => 'fresh-upload', 'upload_token' => 'fresh-token', 'created_at' => now()->subHour()->toISOString(), 'url' => '/images/fresh.png', 'type' => 'image', 'mime_type' => 'image/png'],
            ['id' => 'owned-media', 'upload_token' => null, 'url' => '/images/owned.png', 'type' => 'image', 'mime_type' => 'image/png'],
        ],
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, "composer-{$account->id}-media-item-1");
    waitForComposerAutosaveCondition($page, "JSON.parse(localStorage.getItem('{$key}') ?? 'null')?.media?.length === 2");

    expect($page->script("document.querySelectorAll('[data-testid^=\"composer-{$account->id}-media-item-\"]').length"))->toBe(2)
        ->and($page->script("JSON.parse(localStorage.getItem('{$key}') ?? 'null')?.media?.map((item) => item.id) ?? null"))->toBe(['fresh-upload', 'owned-media']);
    $page->assertNoJavaScriptErrors();
});

test('the composer still works when browser storage throws', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $page->script(<<<'JS'
        (() => {
            const blocked = () => { throw new DOMException('Blocked', 'SecurityError'); };
            Storage.prototype.setItem = blocked;
            Storage.prototype.getItem = blocked;
            Storage.prototype.removeItem = blocked;

            return null;
        })();
    JS);

    openComposerForAutosave($page);
    writeUnfinishedPost($page, $account, 'Storage is blocked');
    closeComposerForAutosave($page);

    openComposerForAutosave($page);
    $page->assertMissing('@composer-resume-dialog');
    writeUnfinishedPost($page, $account, 'Still saves');
    $page->click('@composer-save-draft');
    waitForComposerAutosaveCondition($page, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');

    expect(Post::where('workspace_id', $workspace->id)->sole()->content)->toBe('Still saves');
    $page->assertNoJavaScriptErrors();
});

test('an idea opened as a post neither prompts nor overwrites the unfinished post', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $idea = Idea::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'idea_stage_id' => null,
        'position' => 0,
        'title' => 'Greeting',
        'body' => 'Hello from the idea',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, ['content' => 'Original unfinished', 'accountIds' => [$account->id]]);

    $page->navigate(route('app.create.ideas.show', $idea));
    waitForComposerAutosaveTestId($page, 'idea-editor-create-post');
    $page->click('@idea-editor-create-post');
    waitForComposerAutosaveCondition($page, 'document.querySelector(\'[data-testid="composer-base-content"]\')?.value === "Hello from the idea"');
    $page->assertMissing('@composer-resume-dialog')
        ->fill('@composer-base-content', 'Edited idea text');
    closeComposerForAutosave($page);

    expect($page->script("JSON.parse(localStorage.getItem('{$key}')).content"))->toBe('Original unfinished');

    $page->navigate(route('app.posts.index'));
    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->assertSeeIn('@composer-resume-preview', 'Original unfinished')
        ->assertNoJavaScriptErrors();
});

test('a custom date still in the future is restored with its mode', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Planned post',
        'accountIds' => [$account->id],
        'scheduleMode' => 'custom',
        'scheduledAt' => now()->addDays(3)->format('Y-m-d\TH:i'),
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, 'composer-submit');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('custom')
        ->and($page->script("JSON.parse(localStorage.getItem('{$key}')).scheduledAt"))->toBe(now()->addDays(3)->format('Y-m-d\TH:i'));
    $page->assertNoJavaScriptErrors();
});

test('a custom date already in the past falls back to the default mode', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Missed post',
        'accountIds' => [$account->id],
        'scheduleMode' => 'custom',
        'scheduledAt' => now()->subDay()->format('Y-m-d\TH:i'),
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, 'composer-submit');

    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('now');
    $page->assertNoJavaScriptErrors();
});

test('a failed save keeps the unfinished post and marks a pruned upload on its tile', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Pruned upload',
        'accountIds' => [$account->id],
        'media' => [
            ['id' => 'pruned-upload', 'upload_token' => 'pruned-token', 'created_at' => now()->subHour()->toISOString(), 'url' => '/images/pruned.png', 'path' => 'uploads/pruned.png', 'type' => 'image', 'mime_type' => 'image/png'],
        ],
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, "composer-{$account->id}-media-item-0");
    $page->click('@composer-save-draft');
    waitForComposerAutosaveTestId($page, "composer-{$account->id}-media-error-0");

    $page->assertSeeIn("@composer-{$account->id}-media-error-0", 'This upload expired. Add the file again.')
        ->assertVisible('@post-composer-dialog');
    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(0)
        ->and($page->script("JSON.parse(localStorage.getItem('{$key}')).content"))->toBe('Pruned upload');
    $page->assertNoJavaScriptErrors();
});

test('resuming a single channel adopts its own caption as the post text', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Shared text',
        'accountIds' => [$account->id],
        'overrides' => [$account->id => ['content' => 'Channel text']],
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, "composer-caption-{$account->id}");
    waitForComposerAutosaveCondition($page, "JSON.parse(localStorage.getItem('{$key}') ?? 'null')?.content === 'Channel text'");

    $page->assertValue("@composer-caption-{$account->id}", 'Channel text')
        ->fill("@composer-caption-{$account->id}", 'Channel text edited');
    waitForComposerAutosaveCondition($page, "JSON.parse(localStorage.getItem('{$key}') ?? 'null')?.content === 'Channel text edited'");

    expect($page->script("JSON.parse(localStorage.getItem('{$key}')).overrides['{$account->id}']?.content ?? null"))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('logging out removes every unfinished post from the browser', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, ['content' => 'Private draft', 'accountIds' => [$account->id]]);
    seedComposerAutosave($page, 'trypost:composer:autosave:other:workspace', ['content' => 'Another draft']);
    $page->script("localStorage.setItem('trypost:unrelated', 'kept')");

    waitForComposerAutosaveTestId($page, 'sidebar-workspace-menu');
    $page->click('@sidebar-workspace-menu');
    waitForComposerAutosaveTestId($page, 'logout-button');
    $page->click('@logout-button');
    waitForComposerAutosaveCondition($page, "localStorage.getItem('{$key}') === null");

    expect($page->script("Object.keys(localStorage).filter((key) => key.startsWith('trypost:composer:autosave:')).length"))->toBe(0)
        ->and($page->script("localStorage.getItem('trypost:unrelated')"))->toBe('kept');
});

test('a restored custom time keeps its moment in the channel zone', function () {
    [$user, $workspace, $account] = composerAutosaveWorkspace();
    $user->update(['timezone' => 'Asia/Tokyo', 'time_format' => TimeFormat::TwentyFourHour]);
    $account->update(['timezone' => 'America/Sao_Paulo']);
    $day = now('America/Sao_Paulo')->addDays(3)->format('Y-m-d');
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Planned in Sao Paulo',
        'accountIds' => [$account->id],
        'scheduleMode' => 'custom',
        'scheduledAt' => "{$day}T10:00",
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, 'composer-submit');

    $local = CarbonImmutable::parse("{$day} 10:00", 'America/Sao_Paulo');
    expect(trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-trigger]").textContent')))
        ->toBe($local->format($local->year === now()->year ? 'M j' : 'M j, Y').', 10:00')
        ->and($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('custom');

    $page->click('@composer-submit');
    waitForComposerAutosaveCondition($page, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');

    expect(Post::query()->where('workspace_id', $workspace->id)->sole()->scheduled_at->toIso8601String())
        ->toBe($local->utc()->toIso8601String());
    $page->assertNoJavaScriptErrors();
});

test('an unfinished post from the old shared editor restores one card per network with its own text', function () {
    [$user, $workspace, $linkedin] = composerAutosaveWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Shared text',
        'accountIds' => [$linkedin->id, $x->id],
        'overrides' => [$x->id => ['content' => 'X text']],
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, "composer-caption-{$linkedin->id}");

    waitForComposerAutosaveCondition($page, "JSON.parse(localStorage.getItem('{$key}') ?? 'null')?.overrides?.['{$linkedin->id}']?.content !== undefined");
    expect($page->script("JSON.parse(localStorage.getItem('{$key}')).overrides"))->toEqual([
        $linkedin->id => ['content' => 'Shared text', 'media' => []],
        $x->id => ['content' => 'X text', 'media' => []],
    ]);
    $page->assertValue("@composer-caption-{$linkedin->id}", 'Shared text')
        ->assertSeeIn("@composer-expand-{$x->id}", 'X text')
        ->assertMissing('@composer-base-content')
        ->assertNoJavaScriptErrors();
});

test('an unfinished post with two networks and no per-network text resumes on the shared step', function () {
    [$user, $workspace, $linkedin] = composerAutosaveWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Still shared',
        'accountIds' => [$linkedin->id, $x->id],
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, 'composer-next');

    $page->assertValue('@composer-base-content', 'Still shared')
        ->assertMissing('@composer-customization')
        ->assertMissing('@composer-back')
        ->assertNoJavaScriptErrors();
});

/**
 * @return array<string, string|null>
 */
function composerAutosaveSavedContents(Workspace $workspace): array
{
    return Post::query()->where('workspace_id', $workspace->id)->with('postPlatforms')->get()
        ->mapWithKeys(fn (Post $post): array => [$post->postPlatforms->sole()->social_account_id => $post->content])
        ->all();
}

test('two pages of one network that disagree in an unfinished post restore the first page text', function () {
    [$user, $workspace] = composerAutosaveWorkspace();
    [$first, $second] = SocialAccount::factory()->facebook()->count(2)->create(['workspace_id' => $workspace->id])->all();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Shared text',
        'accountIds' => [$first->id, $second->id],
        'overrides' => [
            $first->id => ['content' => 'First page text'],
            $second->id => ['content' => 'Second page text'],
        ],
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, "composer-caption-{$first->id}");

    waitForComposerAutosaveCondition($page, "JSON.parse(localStorage.getItem('{$key}') ?? 'null')?.content === 'First page text'");
    expect($page->script("JSON.parse(localStorage.getItem('{$key}')).overrides"))->toEqual([
        $first->id => [],
        $second->id => [],
    ]);
    $page->assertValue("@composer-caption-{$first->id}", 'First page text')
        ->assertMissing("@composer-caption-{$second->id}")
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft');
    waitForComposerAutosaveCondition($page, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');

    expect(composerAutosaveSavedContents($workspace))->toEqual([
        $first->id => 'First page text',
        $second->id => 'First page text',
    ]);
});

test('two pages of one network restore the text of the page that owns one', function () {
    [$user, $workspace] = composerAutosaveWorkspace();
    [$first, $second] = SocialAccount::factory()->facebook()->count(2)->create(['workspace_id' => $workspace->id])->all();
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $key = composerAutosaveKeyFor($user, $workspace);
    seedComposerAutosave($page, $key, [
        'content' => 'Shared text',
        'accountIds' => [$first->id, $second->id],
        'overrides' => [$second->id => ['content' => 'Second page text']],
    ]);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, "composer-caption-{$first->id}");

    $page->assertValue("@composer-caption-{$first->id}", 'Second page text')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft');
    waitForComposerAutosaveCondition($page, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');

    expect(composerAutosaveSavedContents($workspace))->toEqual([
        $first->id => 'Second page text',
        $second->id => 'Second page text',
    ]);
});

test('resuming an unfinished post keeps its thread replies', function () {
    [$user, $workspace] = composerAutosaveWorkspace();
    $bluesky = SocialAccount::factory()->bluesky()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    openComposerForAutosave($page);
    writeUnfinishedPost($page, $bluesky, 'Thread root');
    $page->click('@thread-start');
    waitForComposerAutosaveTestId($page, 'thread-reply-0');
    $page->fill('@thread-reply-0', 'Kept reply');
    $key = composerAutosaveKeyFor($user, $workspace);
    waitForComposerAutosaveCondition($page, "JSON.parse(localStorage.getItem('{$key}') ?? 'null')?.overrides?.['{$bluesky->id}']?.meta?.thread_replies?.[0] === 'Kept reply'");
    closeComposerForAutosave($page);

    openComposerForAutosave($page);
    waitForComposerAutosaveTestId($page, 'composer-resume-dialog');
    $page->click('@composer-resume-confirm');
    waitForComposerAutosaveTestId($page, 'thread-reply-collapsed-0');

    $page->assertSeeIn('@thread-reply-collapsed-0', 'Kept reply')
        ->assertValue("@composer-caption-{$bluesky->id}", 'Thread root')
        ->assertNoJavaScriptErrors();
});
