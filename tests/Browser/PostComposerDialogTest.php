<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;

test('new post buttons open the global dialog without changing the page URL', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $calendar = visit(route('app.calendar'));
    $calendar->click('@sidebar-new-post')
        ->assertVisible('@post-composer-dialog')
        ->assertScript('location.pathname + location.search', parse_url(route('app.calendar'), PHP_URL_PATH));

    $posts = visit(route('app.posts.index'));
    $posts->assertVisible('@posts-tabs')
        ->click('@posts-new-post')
        ->assertVisible('@post-composer-dialog')
        ->assertScript('location.pathname + location.search', parse_url(route('app.posts.index'), PHP_URL_PATH));

    visit(route('app.posts.index'))
        ->click('@posts-tab-scheduled')
        ->assertVisible('@posts-tab-scheduled')
        ->assertScript('new URLSearchParams(location.search).get("tab")', 'scheduled');
});

test('composer can search channels, preview a selected account, and expand to the full viewport', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $instagram = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Instagram,
    ]);
    $x = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::X,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->assertVisible('@composer-empty-preview')
        ->assertVisible('@composer-all-accounts')
        ->click('@composer-preview-toggle')
        ->click('@composer-preview-toggle')
        ->assertVisible('@composer-empty-preview');

    $sheet = $page->script(<<<'JS'
        (async () => {
            const panel = document.querySelector('[data-testid="post-composer-dialog"]');
            await Promise.all(panel.getAnimations().map((animation) => animation.finished));
            const rect = panel.getBoundingClientRect();
            return {
                slot: panel.dataset.slot,
                left: rect.left,
                right: rect.right,
                height: rect.height,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
            };
        })()
    JS);
    expect($sheet['slot'])->toBe('sheet-content')
        ->and($sheet['left'])->toBeGreaterThan(0)
        ->and(abs($sheet['right'] - $sheet['viewportWidth']))->toBeLessThan(2)
        ->and(abs($sheet['height'] - $sheet['viewportHeight']))->toBeLessThan(2);

    $page->click('@composer-expand-dialog');

    $viewport = $page->script(<<<'JS'
        (async () => {
            const dialog = document.querySelector('[data-testid="post-composer-dialog"]');
            for (let attempt = 0; attempt < 30; attempt++) {
                if (Math.abs(dialog.getBoundingClientRect().width - window.innerWidth) < 2) break;
                await new Promise((resolve) => setTimeout(resolve, 20));
            }
            return {
                width: dialog.getBoundingClientRect().width,
                height: dialog.getBoundingClientRect().height,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
            };
        })()
    JS);
    expect(abs($viewport['width'] - $viewport['viewportWidth']))->toBeLessThan(2)
        ->and(abs($viewport['height'] - $viewport['viewportHeight']))->toBeLessThan(2);
    expect($page->script('document.querySelectorAll("[data-testid=composer-all-accounts] img").length'))->toBe(4);

    $composerPosition = <<<'JS'
        (async () => {
            const content = document.querySelector('[data-slot="popover-content"]');
            await Promise.all(content.getAnimations().map((animation) => animation.finished));
            const popover = content.getBoundingClientRect();
            const editor = document.querySelector('[data-testid="composer-base-content"]').parentElement.getBoundingClientRect();
            return { popoverLeft: popover.left, popoverTop: popover.top, editorTop: editor.top };
        })()
    JS;
    $page->click('@composer-add-account');
    $beforeSelection = $page->script($composerPosition);
    $page->click('@composer-select-all');
    $afterSelection = $page->script($composerPosition);
    expect(abs($afterSelection['popoverLeft'] - $beforeSelection['popoverLeft']))->toBeLessThan(2)
        ->and(abs($afterSelection['popoverTop'] - $beforeSelection['popoverTop']))->toBeLessThan(2)
        ->and(abs($afterSelection['editorTop'] - $beforeSelection['editorTop']))->toBeLessThan(2);

    $page
        ->assertVisible("@composer-account-{$instagram->id}")
        ->assertVisible("@composer-account-{$x->id}")
        ->click('@composer-select-all')
        ->assertMissing("@composer-account-{$instagram->id}")
        ->assertMissing("@composer-account-{$x->id}")
        ->fill('@composer-account-search', 'Instagram')
        ->assertVisible("@composer-account-option-{$instagram->id}")
        ->assertMissing("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$instagram->id}")
        ->assertVisible('@composer-account-search')
        ->fill('@composer-account-search', '')
        ->click("@composer-account-option-{$x->id}")
        ->assertVisible("@composer-account-option-{$instagram->id}")
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-{$instagram->id}")
        ->assertVisible('[data-slot="tooltip-content"]')
        ->assertVisible("@composer-account-{$instagram->id}")
        ->click("@composer-remove-account-{$instagram->id}")
        ->assertMissing("@composer-account-{$instagram->id}")
        ->click('@composer-add-account')
        ->fill('@composer-account-search', '')
        ->click('@composer-select-all')
        ->fill('@composer-base-content', 'Preview this caption')
        ->assertVisible('@composer-preview-card')
        ->assertSee('Preview this caption');
    expect($page->script('document.querySelectorAll("[data-testid=composer-phone-preview]").length'))->toBe(2);
    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="composer-phone-preview"]')].every((frame) => frame.getBoundingClientRect().height > 500)
    JS))->toBeTrue();

    $page
        ->click('@composer-next')
        ->assertVisible('@composer-customization');
    expect($page->script('document.querySelectorAll("[data-testid=composer-phone-preview]").length'))->toBe(1);

    $popoverPosition = <<<'JS'
        (async () => {
            const content = document.querySelector('[data-slot="popover-content"]');
            await Promise.all(content.getAnimations().map((animation) => animation.finished));
            const rect = content.getBoundingClientRect();
            return { left: rect.left, top: rect.top };
        })()
    JS;
    $page->click('@composer-add-account');
    $beforeCustomizationSelection = $page->script($popoverPosition);
    $page->click("@composer-account-option-{$x->id}");
    $afterCustomizationSelection = $page->script($popoverPosition);
    expect(abs($afterCustomizationSelection['left'] - $beforeCustomizationSelection['left']))->toBeLessThan(2)
        ->and(abs($afterCustomizationSelection['top'] - $beforeCustomizationSelection['top']))->toBeLessThan(2);

    $page->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-{$instagram->id}")
        ->assertVisible("@composer-account-{$instagram->id}")
        ->click("@composer-remove-account-{$instagram->id}")
        ->assertMissing("@composer-account-{$instagram->id}")
        ->assertVisible("@composer-account-{$x->id}")
        ->assertVisible('@composer-customization');
});

test('composer reuses the phone previews for Facebook and TikTok even before media is uploaded', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::Facebook,
    ]);
    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::TikTok,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-add-account')
        ->click('@composer-select-all')
        ->fill('@composer-base-content', 'Facebook and TikTok preview');

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="composer-preview-card"]')].map((card) => ({
            platform: card.querySelector('h4')?.textContent?.trim(),
            hasPhone: Boolean(card.querySelector('[data-testid="composer-phone-preview"]')),
            hasCaption: card.textContent?.includes('Facebook and TikTok preview'),
        }))
    JS))->toEqual([
        ['platform' => 'Facebook', 'hasPhone' => true, 'hasCaption' => true],
        ['platform' => 'TikTok', 'hasPhone' => true, 'hasCaption' => true],
    ]);
});

test('composer slide-over fills the mobile viewport without horizontal overflow', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'))->resize(375, 812);
    $page->assertVisible('@post-composer-dialog')
        ->click('@composer-add-account')
        ->click('@composer-select-all')
        ->fill('@composer-base-content', 'Mobile preview')
        ->click('@composer-preview-toggle')
        ->assertVisible('@composer-preview-card');

    $dimensions = $page->script(<<<'JS'
        (async () => {
            const panel = document.querySelector('[data-testid="post-composer-dialog"]');
            await Promise.all(panel.getAnimations().map((animation) => animation.finished));
            const rect = panel.getBoundingClientRect();
            return {
                left: rect.left,
                right: rect.right,
                width: rect.width,
                height: rect.height,
                viewportWidth: window.innerWidth,
                viewportHeight: window.innerHeight,
                scrollWidth: panel.scrollWidth,
            };
        })()
    JS);

    expect(abs($dimensions['left']))->toBeLessThan(2)
        ->and(abs($dimensions['right'] - $dimensions['viewportWidth']))->toBeLessThan(2)
        ->and(abs($dimensions['width'] - $dimensions['viewportWidth']))->toBeLessThan(2)
        ->and(abs($dimensions['height'] - $dimensions['viewportHeight']))->toBeLessThan(2)
        ->and($dimensions['scrollWidth'])->toBeLessThanOrEqual($dimensions['viewportWidth']);

    $page->click('@composer-mobile-compose')
        ->assertVisible('@composer-base-content');
});

test('composer searches and selects multiple labels and exposes emoji and signatures below media', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $firstLabel = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Marketing']);
    $secondLabel = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Product']);
    WorkspaceSignature::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign signature', 'content' => '#campaign']);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-tags-trigger')
        ->fill('@composer-label-search', 'Mark')
        ->assertVisible("@composer-label-{$firstLabel->id}")
        ->assertMissing("@composer-label-{$secondLabel->id}")
        ->click("@composer-label-{$firstLabel->id}")
        ->assertVisible('@composer-label-search')
        ->fill('@composer-label-search', 'Prod')
        ->click("@composer-label-{$secondLabel->id}")
        ->assertVisible('@composer-label-search');

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid^="composer-label-"][role="option"]')].map((option) => [option.dataset.testid, option.getAttribute('aria-selected')])
    JS))->toEqual([["composer-label-{$secondLabel->id}", 'true']]);

    $page->fill('@composer-label-search', '')
        ->assertVisible("@composer-label-{$firstLabel->id}");
    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid^="composer-label-"][role="option"]')].filter((option) => option.getAttribute('aria-selected') === 'true').length
    JS))->toBe(2);

    $page->click('@composer-tags-trigger')
        ->click('@composer-base-emoji')
        ->click('button[aria-label="grinning face"]')
        ->assertValue('@composer-base-content', '😀')
        ->click('@composer-base-signature')
        ->click('text=Campaign signature')
        ->assertValue('@composer-base-content', "😀\n\n#campaign")
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->click('@composer-next')
        ->assertVisible("@composer-{$account->id}-toolbar")
        ->click("@composer-{$account->id}-emoji")
        ->click('button[aria-label="grinning face with big eyes"]')
        ->assertValue("@composer-caption-{$account->id}", "😀\n\n#campaign😃")
        ->click('@composer-save-draft');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    expect(Post::where('workspace_id', $workspace->id)->sole()->labels()->pluck('workspace_labels.id')->all())
        ->toHaveCount(2)
        ->toContain($firstLabel->id)
        ->toContain($secondLabel->id);
});

test('creating a four account draft keeps composition in the browser until save', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $accounts = SocialAccount::factory()->count(4)->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->assertVisible('@post-composer-dialog');
    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(0);
    expect($page->script('getComputedStyle(document.querySelector("[data-testid=composer-all-accounts] [aria-hidden=true]")).filter'))->toBe('grayscale(1)')
        ->and($page->script('document.querySelector("[data-testid=composer-all-accounts]").textContent'))->toContain('+2');

    $page->click('@composer-add-account');
    foreach ($accounts as $account) {
        $page->click("@composer-account-option-{$account->id}")
            ->assertVisible('@composer-account-search');
    }
    expect($page->script('getComputedStyle(document.querySelector("[data-testid^=composer-account-] img")).filter'))->toBe('none');

    $page->assertVisible('@composer-save-draft')
        ->assertVisible('@composer-schedule-trigger')
        ->fill('@composer-base-content', 'A shared announcement');

    expect($page->script('document.querySelectorAll("[data-testid=\"composer-preview-card\"]").length'))->toBe(4);
    expect($page->script(<<<'JS'
        (() => {
            const list = document.querySelector('[data-testid="composer-previews-scroll"]');
            if (!list || list.scrollHeight <= list.clientHeight) return false;
            list.scrollTop = list.scrollHeight;
            return list.scrollTop > 0;
        })()
    JS))->toBeTrue();

    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(0);

    $page->click('@composer-save-draft');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(4);
    expect(Post::where('workspace_id', $workspace->id)->pluck('content')->all())
        ->each->toBe('A shared announcement');
});

test('composer offers only immediate publishing and an explicit date and time', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->click('@composer-schedule-trigger')
        ->assertVisible('@composer-schedule-now')
        ->assertVisible('@composer-schedule-custom')
        ->assertDontSee('Next Available')
        ->assertDontSee('Prioritize')
        ->click('@composer-schedule-custom')
        ->assertVisible('@post-time-picker');

    $page->click('button:has-text("Cancel")')
        ->click('@composer-schedule-trigger')
        ->click('@composer-schedule-now')
        ->click('@composer-next')
        ->assertVisible('@composer-submit');
    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('now');
});

test('create another reopens a fresh composer after saving a draft', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->fill('@composer-base-content', 'First draft')
        ->click('@composer-create-another')
        ->click('@composer-save-draft');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                const composer = document.querySelector('[data-testid="composer-base-content"]');
                if (composer && composer.value === '') return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', '');
    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('composer has the assistant in its sidebar and no template shortcut', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    visit(route('app.posts.create'))
        ->assertMissing('@composer-templates')
        ->click('@composer-ai-assistant')
        ->assertVisible('@composer-assistant-panel')
        ->click('@composer-ai-write_more')
        ->assertVisible('@composer-ai-prompt')
        ->assertVisible('@composer-ai-generate');
});

test('recovering an empty-target draft retains its caption media and labels', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create();
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $legacy = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'Keep this draft',
        'media' => [MediaItem::fromMedia($asset)->toArray()],
    ]);
    $legacy->labels()->attach($label);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $legacy));
    $page->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', 'Keep this draft')
        ->click('@composer-add-account')->click("@composer-account-option-{$account->id}")
        ->click('@composer-next')
        ->click('@composer-save-draft');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $recovered = Post::where('workspace_id', $workspace->id)->sole();
    expect($recovered->id)->not->toBe($legacy->id)
        ->and($recovered->content)->toBe('Keep this draft')
        ->and(data_get($recovered->media, '0.id'))->toBe($asset->id)
        ->and($recovered->labels()->sole()->id)->toBe($label->id)
        ->and($recovered->postPlatforms()->sole()->social_account_id)->toBe($account->id);
});

test('a comment deep link and AI assistant remain available in the edit dialog', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'A draft to discuss',
    ]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
    $comment = PostComment::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Please review the opening line',
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', ['post' => $post, 'tab' => 'comments', 'comment' => $comment->id]));
    $page->assertVisible('@post-composer-dialog')
        ->assertVisible('@composer-comments-panel')
        ->assertSee('Please review the opening line')
        ->click('@composer-back-to-post')
        ->click('@composer-ai-assistant')
        ->assertVisible('@composer-assistant-panel')
        ->assertVisible('@composer-ai-rephrase')
        ->click('@composer-ai-assistant')
        ->assertVisible('@composer-assistant-panel')
        ->click('@composer-preview-toggle')
        ->assertVisible('@composer-previews-scroll')
        ->assertMissing('@composer-assistant-panel')
        ->click('@composer-preview-toggle')
        ->assertVisible('@composer-previews-scroll');
});

test('account overrides inherit later shared edits and are discarded when an account is removed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $firstId = $accounts[0]->id;
    $secondId = $accounts[1]->id;
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-add-account')->click("@composer-account-option-{$firstId}")
        ->click("@composer-account-option-{$secondId}")
        ->fill('@composer-base-content', 'Shared first')
        ->click('@composer-next')
        ->fill("@composer-caption-{$firstId}", '')
        ->click('@composer-back')
        ->fill('@composer-base-content', 'Shared second')
        ->click('@composer-next');

    $firstCaption = $page->script("document.querySelector('[data-testid=\"composer-caption-{$firstId}\"]').value");
    $page->click("@composer-expand-{$secondId}");
    $secondCaption = $page->script("document.querySelector('[data-testid=\"composer-caption-{$secondId}\"]').value");
    expect($firstCaption)->toBe('')
        ->and($secondCaption)->toBe('Shared second');

    $page->click('@composer-back')
        ->click("@composer-remove-account-{$firstId}")
        ->click('@composer-add-account')->click("@composer-account-option-{$firstId}")
        ->click('@composer-next')
        ->click("@composer-expand-{$firstId}");

    expect($page->script("document.querySelector('[data-testid=\"composer-caption-{$firstId}\"]').value"))
        ->toBe('Shared second');
});

test('X character count uses the same link defusing as its preview', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-add-account')->click("@composer-account-option-{$account->id}")
        ->fill('@composer-base-content', 'https://example.com/post')
        ->click('@composer-next')
        ->assertVisible('@composer-x-count');

    expect(trim((string) $page->script("document.querySelector('[data-testid=\"composer-x-count\"]').textContent")))
        ->toBe((string) mb_strlen('example(.)com/post'));
    $page->assertSee('example(.)com/post');
});

test('selecting a new image uploads an asset before any post is saved', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-add-account')->click("@composer-account-option-{$account->id}")
        ->click('@composer-base-media');

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const input = document.querySelector('input[type="file"]');
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'new-composer-image.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('img[alt="new-composer-image.png"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    expect(Media::where('mediable_id', $workspace->id)->where('collection', 'assets')->count())->toBe(1)
        ->and(Post::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('dropping an image into the composer uploads it without saving a post', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-add-account')->click("@composer-account-option-{$account->id}");

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'dropped-image.png', { type: 'image/png' }));
            document.querySelector('[data-testid="composer-base-content"]').parentElement.dispatchEvent(
                new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }),
            );
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelectorAll('[data-testid="composer-media-item"]').length === 1) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertVisible('@composer-media-item');
    expect(Media::where('mediable_id', $workspace->id)->where('collection', 'assets')->count())->toBe(1)
        ->and(Post::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('cropping one account creates a separate asset and leaves the other account image intact', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    $page->click('@composer-add-account');
    foreach ($accounts as $account) {
        $page->click("@composer-account-option-{$account->id}");
    }
    $page->fill('@composer-base-content', 'Image for both')
        ->click('@composer-base-media');

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const input = document.querySelector('input[type="file"]');
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'crop-for-channel.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('img[alt="crop-for-channel.png"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $originalId = Media::where('mediable_id', $workspace->id)->where('collection', 'assets')->sole()->id;
    $page->click('img[alt="crop-for-channel.png"]')
        ->click('@media-picker-confirm')
        ->click('@composer-next');

    // The browser test uses Laravel's private local disk, whose unsigned /storage
    // URL cannot be served. Give the crop canvas the uploaded fixture bytes.
    $page->script(<<<JS
        (() => {
            const fixture = 'data:image/png;base64,{$base64}';
            const property = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
            Object.defineProperty(HTMLImageElement.prototype, 'src', {
                configurable: true,
                get() { return property.get.call(this); },
                set(value) { return property.set.call(this, value.includes('/storage/') ? fixture : value); },
            });
        })();
    JS);

    $page->click("@composer-crop-{$accounts[0]->id}-0")
        ->click('@crop-save');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const save = document.querySelector('[data-testid="composer-save-draft"]');
                if (save && !save.disabled && !document.querySelector('[data-testid="crop-save"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    $page->click('@composer-save-draft');

    $posts = Post::where('workspace_id', $workspace->id)->with('postPlatforms')->get()->keyBy(
        fn (Post $post) => $post->postPlatforms->sole()->social_account_id,
    );
    expect($posts)->toHaveCount(2)
        ->and($posts[$accounts[0]->id]->media[0]['id'])->not->toBe($originalId)
        ->and($posts[$accounts[1]->id]->media[0]['id'])->toBe($originalId);
});

test('animated GIFs and videos do not offer the static image crop action', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'media' => [
            ['id' => fake()->uuid(), 'url' => '/storage/animation.gif', 'path' => 'animation.gif', 'type' => 'image', 'mime_type' => 'image/gif'],
            ['id' => fake()->uuid(), 'url' => '/storage/video.mp4', 'path' => 'video.mp4', 'type' => 'video', 'mime_type' => 'video/mp4'],
        ],
    ]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::X, 'enabled' => true]);
    $this->actingAs($user);

    visit(route('app.posts.edit', $post))
        ->assertVisible('@composer-customization')
        ->assertMissing("@composer-crop-{$account->id}-0")
        ->assertMissing("@composer-crop-{$account->id}-1");
});

test('failed crop upload keeps the original asset selected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $asset = Media::factory()->assets()->for($workspace, 'mediable')->create(['mime_type' => 'image/png', 'original_filename' => 'original.png']);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Keep the original',
        'media' => [['id' => $asset->id, 'path' => $asset->path, 'url' => $asset->url, 'type' => 'image', 'mime_type' => 'image/png', 'original_filename' => 'original.png']],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
        'enabled' => true,
    ]);
    $this->actingAs($user);
    $page = visit(route('app.posts.edit', $post));
    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (() => {
            const fixture = 'data:image/png;base64,{$base64}';
            const property = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
            Object.defineProperty(HTMLImageElement.prototype, 'src', {
                configurable: true,
                get() { return property.get.call(this); },
                set(value) { return property.set.call(this, value.includes('/storage/') ? fixture : value); },
            });
            const originalFetch = window.fetch;
            window.fetch = (...args) => {
                if (String(args[0]).includes('/assets/chunked')) {
                    window.__cropUploadFailed = true;
                    return Promise.resolve(new Response('failed', { status: 500 }));
                }
                return originalFetch(...args);
            };
        })();
    JS);

    $page->click("@composer-crop-{$account->id}-0")
        ->click('@crop-save');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if (window.__cropUploadFailed && document.querySelector('[data-testid="composer-save-draft"]')?.disabled === false) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    expect($page->script('Boolean(window.__cropUploadFailed)'))->toBeTrue();
    $page->click('@composer-save-draft');

    expect($post->fresh()->media[0]['id'])->toBe($asset->id)
        ->and(Media::where('mediable_id', $workspace->id)->where('collection', 'assets')->count())->toBe(1);
});

test('settled legacy multi-target history appears as one read-only card per target', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => PostStatus::Published]);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    foreach ($accounts as $account) {
        PostPlatform::factory()->published()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::LinkedIn, 'enabled' => true]);
    }
    $this->actingAs($user);

    $page = visit(route('app.posts.index'));
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                if (document.querySelectorAll('[data-testid^="post-card-"]').length === 2) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    expect($page->script('document.querySelectorAll("[data-testid^=post-card-]").length'))->toBe(2)
        ->and(Post::where('workspace_id', $workspace->id)->count())->toBe(1);
});
