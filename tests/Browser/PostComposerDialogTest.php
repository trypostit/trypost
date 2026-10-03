<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use Illuminate\Support\Facades\Storage;

/**
 * The composer opens as an animated dialog; a click that lands before the
 * animation settles is swallowed. Poll from the page (never sleep()) until the
 * dialog is open and still.
 */
function waitForComposerReady(mixed $page, string $testId = 'composer-add-account'): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
                if (sheet?.getAttribute('data-state') === 'open'
                    && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                    && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

test('schedule view switch navigates between the list and month calendar', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    visit(route('app.posts.index'))
        ->assertVisible('@schedule-view-list')
        ->assertAttribute('@schedule-view-list', 'aria-current', 'page')
        ->click('@schedule-view-calendar')
        ->assertScript('location.pathname', '/schedule/calendar/month')
        ->assertAttribute('@schedule-view-calendar', 'aria-current', 'page')
        ->click('@schedule-view-list')
        ->assertScript('location.pathname', '/schedule')
        ->assertAttribute('@schedule-view-list', 'aria-current', 'page')
        ->assertNoJavaScriptErrors();
});

test('posts label filter searches and selects multiple labels with checkboxes', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $marketing = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Marketing']);
    $sales = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Sales']);
    $marketingPost = Post::factory()->draft()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $salesPost = Post::factory()->draft()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $marketingPost->labels()->attach($marketing);
    $salesPost->labels()->attach($sales);
    $this->actingAs($user);

    visit(route('app.posts.index', ['tab' => 'drafts']))
        ->click('@posts-label-filter')
        ->fill('@posts-label-search', 'Market')
        ->assertVisible("@posts-label-option-{$marketing->id}")
        ->assertMissing("@posts-label-option-{$sales->id}")
        ->click("@posts-label-checkbox-{$marketing->id}")
        ->assertVisible('@posts-label-search')
        ->assertVisible("@post-card-{$marketingPost->id}")
        ->assertMissing("@post-card-{$salesPost->id}")
        ->fill('@posts-label-search', '')
        ->click("@posts-label-option-{$sales->id}")
        ->assertVisible("@post-card-{$marketingPost->id}")
        ->assertVisible("@post-card-{$salesPost->id}")
        ->assertScript('Array.from(new URLSearchParams(location.search).keys()).filter((key) => key.startsWith("labels[")).length', 2)
        ->click('@posts-label-clear')
        ->assertVisible("@post-card-{$marketingPost->id}")
        ->assertVisible("@post-card-{$salesPost->id}")
        ->assertScript('Array.from(new URLSearchParams(location.search).keys()).some((key) => key.startsWith("labels["))', false)
        ->assertNoJavaScriptErrors();
});

test('posts channel filter keeps accounts distinct and persists across tabs', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $firstInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'username' => 'first_channel']);
    $secondInstagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'username' => 'second_channel']);
    $firstPost = Post::factory()->draft()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    $secondPost = Post::factory()->draft()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    PostPlatform::factory()->instagram()->create(['post_id' => $firstPost->id, 'social_account_id' => $firstInstagram->id]);
    PostPlatform::factory()->instagram()->create(['post_id' => $secondPost->id, 'social_account_id' => $secondInstagram->id]);
    $this->actingAs($user);

    visit(route('app.posts.index', ['tab' => 'drafts']))
        ->click('@posts-channel-filter')
        ->fill('@posts-channel-search', 'first_channel')
        ->assertVisible("@posts-channel-option-{$firstInstagram->id}")
        ->assertMissing("@posts-channel-option-{$secondInstagram->id}")
        ->click("@posts-channel-checkbox-{$firstInstagram->id}")
        ->assertVisible("@post-card-{$firstPost->id}")
        ->assertMissing("@post-card-{$secondPost->id}")
        ->assertScript('history.state.page.props.counts.drafts', 1)
        ->assertSeeIn('@publish-tab-count-drafts', '1')
        ->click('@posts-channel-filter')
        ->click('@posts-channel-filter')
        ->assertVisible("@posts-channel-option-{$secondInstagram->id}")
        ->click("@posts-channel-option-{$secondInstagram->id}")
        ->assertVisible("@post-card-{$firstPost->id}")
        ->assertVisible("@post-card-{$secondPost->id}")
        ->click("@posts-channel-option-{$secondInstagram->id}")
        ->assertMissing("@post-card-{$secondPost->id}")
        ->assertScript('Array.from(new URLSearchParams(location.search).keys()).some((key) => key.startsWith("channels["))', true)
        ->click('@publish-tab-sent')
        ->assertScript('new URLSearchParams(location.search).get("tab")', 'sent')
        ->click('@publish-tab-drafts')
        ->assertVisible("@post-card-{$firstPost->id}")
        ->assertMissing("@post-card-{$secondPost->id}");
});

test('new post buttons open the global dialog without changing the page URL', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $calendar = visit(route('app.calendar'));
    $calendar->click('@sidebar-new')
        ->click('@sidebar-new-post')
        ->assertVisible('@post-composer-dialog')
        ->assertScript('location.pathname + location.search', parse_url(route('app.calendar'), PHP_URL_PATH));

    $posts = visit(route('app.posts.index'));
    $posts->assertVisible('@posts-tabs')
        ->click('@posts-new-post')
        ->assertVisible('@post-composer-dialog')
        ->assertMissing('@composer-comments')
        ->assertScript('location.pathname + location.search', parse_url(route('app.posts.index'), PHP_URL_PATH));

    visit(route('app.posts.index'))
        ->click('@publish-tab-sent')
        ->assertVisible('@publish-tab-sent')
        ->assertScript('new URLSearchParams(location.search).get("tab")', 'sent');
});

test('the global composer opens without accessibility or attribute warnings', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    $page->assertVisible('@sidebar-new');
    $page->script(<<<'JS'
        (() => {
            window.__composerWarnings = [];
            const warn = console.warn;
            console.warn = (...args) => {
                window.__composerWarnings.push(args.map(String).join(' '));
                warn(...args);
            };
        })();
    JS);

    $page->click('@sidebar-new')->click('@sidebar-new-post');
    waitForComposerReady($page);

    $page->assertVisible('@post-composer-dialog')->assertNoJavaScriptErrors();
    expect($page->script('window.__composerWarnings'))->toBe([]);
});

test('composer can search channels, preview a selected account, and expand to the full viewport', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
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
        ->assertVisible('[data-testid="composer-empty-preview"] [data-testid="publish-empty-illustration"]')
        ->assertSeeIn('@composer-empty-preview', __('posts.composer.preview_empty'))
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
    expect($sheet['slot'])->toBe('dialog-content')
        ->and($sheet['left'])->toBeGreaterThan(0)
        ->and(abs($sheet['left'] - ($sheet['viewportWidth'] - $sheet['right'])))->toBeLessThan(2)
        ->and(abs($sheet['height'] - min($sheet['viewportHeight'] - 48, 888)))->toBeLessThan(2);

    $page->click('@composer-expand-dialog');

    $viewport = $page->script(<<<'JS'
        (async () => {
            const dialog = document.querySelector('[data-testid="post-composer-dialog"]');
            for (let attempt = 0; attempt < 300; attempt++) {
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
    expect($page->script('document.querySelectorAll("[data-testid=composer-all-accounts] [data-slot=avatar]").length'))->toBe(2)
        ->and($page->script('document.querySelectorAll("[data-testid=composer-all-accounts] img").length'))->toBe(2);

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
    expect($page->script('document.querySelectorAll("[data-testid=composer-preview-frame]").length'))->toBe(2);
    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="composer-preview-frame"]')].every((frame) => frame.getBoundingClientRect().width > 280)
    JS))->toBeTrue();

    $page
        ->click('@composer-next')
        ->assertVisible('@composer-customization');
    expect($page->script('document.querySelectorAll("[data-testid=composer-preview-frame]").length'))->toBe(1);

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

test('composer renders the Facebook and TikTok previews even before media is uploaded', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
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
    waitForComposerReady($page);
    $page->click('@composer-add-account')
        ->click('@composer-select-all')
        ->fill('@composer-base-content', 'Facebook and TikTok preview');

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid="composer-preview-card"]')].map((card) => ({
            platform: card.querySelector('h4')?.textContent?.trim(),
            hasFrame: Boolean(card.querySelector('[data-testid="composer-preview-frame"]')),
            hasCaption: card.textContent?.includes('Facebook and TikTok preview'),
        }))
    JS))->toEqual([
        ['platform' => 'Facebook', 'hasFrame' => true, 'hasCaption' => true],
        ['platform' => 'TikTok', 'hasFrame' => true, 'hasCaption' => true],
    ]);
});

test('composer slide-over fills the mobile viewport without horizontal overflow', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'))->resize(375, 812);
    $page->assertVisible('@post-composer-dialog')
        ->fill('@composer-base-content', 'Mobile preview')
        ->click('@composer-add-account')
        ->click('@composer-select-all')
        ->click('@composer-preview-toggle')
        ->assertVisible('@composer-preview-frame');

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
        ->assertValue("@composer-caption-{$account->id}", 'Mobile preview');
});

test('composer searches and selects multiple labels and exposes emoji and signatures below media', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $firstLabel = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Marketing']);
    $secondLabel = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Product']);
    WorkspaceSignature::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Campaign signature', 'content' => '#campaign']);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->click('@composer-tags-trigger')
        ->fill('@composer-label-search', 'Mark')
        ->assertVisible("@composer-label-option-{$firstLabel->id}")
        ->assertMissing("@composer-label-option-{$secondLabel->id}")
        ->click("@composer-label-option-{$firstLabel->id}")
        ->assertVisible('@composer-label-search')
        ->fill('@composer-label-search', 'Prod')
        ->click("@composer-label-option-{$secondLabel->id}")
        ->assertVisible('@composer-label-search')
        ->assertAttribute("@composer-label-checkbox-{$secondLabel->id}", 'data-state', 'checked')
        ->fill('@composer-label-search', '')
        ->assertAttribute("@composer-label-checkbox-{$firstLabel->id}", 'data-state', 'checked')
        ->assertAttribute("@composer-label-checkbox-{$secondLabel->id}", 'data-state', 'checked');

    $page->click('@composer-tags-trigger')
        ->click('@composer-base-emoji')
        ->click('button[aria-label="grinning face"]')
        ->assertValue('@composer-base-content', '😀')
        ->click('@composer-base-signature')
        ->click('text=Campaign signature')
        ->assertValue('@composer-base-content', "😀\n\n#campaign")
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->assertVisible("@composer-{$account->id}-toolbar")
        ->click("@composer-{$account->id}-emoji")
        ->click('button[aria-label="grinning face with big eyes"]')
        ->assertValue("@composer-caption-{$account->id}", "😀\n\n#campaign😃")
        ->click('@composer-save-draft');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
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

test('composer creates and edits signatures in the popover without losing the draft', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->fill('@composer-base-content', 'Draft text')
        ->click('@composer-base-signature')
        ->assertVisible('@composer-signatures-popover')
        ->assertSeeIn('@composer-signatures-empty', __('signatures.empty_title'))
        ->assertMissing('@composer-create-signature')
        ->click('@composer-signatures-empty-create')
        ->click('@submit-composer-signature')
        ->assertVisible('@composer-signature-name')
        ->assertValue('@composer-base-content', 'Draft text')
        ->fill('@composer-signature-name', 'Launch tags')
        ->fill('@composer-signature-content', '#launch')
        ->click('@submit-composer-signature')
        ->assertSee('Launch tags');

    $signature = $workspace->signatures()->where('name', 'Launch tags')->firstOrFail();

    $page->click('button[aria-label="Edit signature"]')
        ->fill('@composer-signature-content', '#launch #updated')
        ->click('@submit-composer-signature')
        ->assertSee('#launch #updated')
        ->click('text=Launch tags')
        ->assertValue('@composer-base-content', "Draft text\n\n#launch #updated")
        ->click('@composer-base-signature')
        ->click('@composer-create-signature')
        ->assertValue('@composer-signature-name', '')
        ->assertValue('@composer-signature-content', '')
        ->assertNoJavaScriptErrors();

    expect($signature->fresh()->content)->toBe('#launch #updated');
});

test('a label created from the empty label picker of the global composer is listed and selected without losing the draft', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    $page->click('@sidebar-new')->click('@sidebar-new-post');
    waitForComposerReady($page);
    $page->fill('@composer-base-content', 'Draft text')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->click('@composer-tags-trigger')
        ->click('@composer-label-create');
    waitForComposerReady($page, 'create-label-name');
    $page->fill('@create-label-name', 'Launch')
        ->click('@submit-create-label')
        ->assertSeeIn('@composer-tags-trigger', 'Launch')
        ->assertValue("@composer-caption-{$account->id}", 'Draft text');

    $label = $workspace->labels()->sole();

    $page->click('@composer-tags-trigger')
        ->assertAttribute("@composer-label-checkbox-{$label->id}", 'data-state', 'checked')
        ->assertNoJavaScriptErrors();
});

test('a label created while editing a post on the publish page is listed and selected without losing the draft', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->draft()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id]);
    PostPlatform::factory()->linkedin()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));
    waitForComposerReady($page, 'composer-tags-trigger');
    $page->fill("@composer-caption-{$account->id}", 'Edited text')
        ->click('@composer-tags-trigger')
        ->click('@composer-label-create');
    waitForComposerReady($page, 'create-label-name');
    $page->fill('@create-label-name', 'Launch')
        ->click('@submit-create-label')
        ->assertSeeIn('@composer-tags-trigger', 'Launch')
        ->assertValue("@composer-caption-{$account->id}", 'Edited text')
        ->assertNoJavaScriptErrors();
});

test('the composer channel picker offers to connect a channel when the workspace has none', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->click('@composer-add-account')
        ->assertSeeIn('@composer-accounts-empty', __('posts.no_channels'))
        ->click('@composer-accounts-connect');
    waitForComposerReady($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
});

test('creating a four account draft keeps composition in the browser until save', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $accounts = collect([Platform::Instagram, Platform::LinkedIn, Platform::X, Platform::Facebook])
        ->map(fn (Platform $platform): SocialAccount => SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => $platform,
        ]));

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
            for (let attempt = 0; attempt < 300; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(4);
    expect(Post::where('workspace_id', $workspace->id)->pluck('content')->all())
        ->each->toBe('A shared announcement');
});

test('a channel without posting times keeps immediate publishing and an explicit date and time selectable', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->click('@composer-schedule-trigger')
        ->assertVisible('@composer-schedule-now')
        ->assertVisible('@composer-schedule-custom')
        ->assertDisabled('@composer-schedule-next')
        ->assertDisabled('@composer-schedule-top')
        ->click('@composer-schedule-custom')
        ->assertVisible('@composer-schedule-picker');

    $page->click('@composer-schedule-more-actions')
        ->click('@composer-schedule-now')
        ->assertVisible('@composer-submit');
    expect($page->script('document.querySelector("[data-testid=composer-submit]").dataset.scheduleMode'))->toBe('now');
});

test('create another reopens a fresh composer after saving a draft', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->fill("@composer-caption-{$account->id}", 'First draft')
        ->click('@composer-create-another')
        ->click('@composer-save-draft');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
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
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    visit(route('app.posts.create'))
        ->assertMissing('@composer-templates')
        ->click('@composer-ai-assistant')
        ->assertVisible('@composer-assistant-panel')
        ->assertVisible('@writing-assistant-panel')
        ->click('@writing-assistant-mode-generate')
        ->assertVisible('@writing-assistant-prompt')
        ->assertVisible('@writing-assistant-generate');
});

test('recovering an empty-target draft retains its caption media and labels', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $label = WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]);
    $legacy = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'Keep this draft',
    ]);
    $asset = Media::factory()->ownedByPost($legacy)->create();
    Storage::put($asset->path, (string) file_get_contents(base_path('tests/fixtures/1x1.png')));
    $legacy->update(['media' => [MediaItem::fromMedia($asset)->toArray()]]);
    $legacy->labels()->attach($label);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $legacy));
    $page->assertVisible('@post-composer-dialog')
        ->assertValue('@composer-base-content', 'Keep this draft')
        ->click('@composer-add-account')->click("@composer-account-option-{$account->id}")
        ->assertValue("@composer-caption-{$account->id}", 'Keep this draft')
        ->click('@composer-save-draft');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $recovered = Post::where('workspace_id', $workspace->id)->sole();
    $owned = $recovered->ownedMedia()->sole();
    expect($recovered->id)->not->toBe($legacy->id)
        ->and($recovered->content)->toBe('Keep this draft')
        ->and(data_get($recovered->media, '0.id'))->toBe($owned->id)
        ->and($owned->id)->toBe($asset->id)
        ->and($owned->path)->toBe($asset->path)
        ->and(Media::query()->count())->toBe(1)
        ->and($recovered->labels()->sole()->id)->toBe($label->id)
        ->and($recovered->postPlatforms()->sole()->social_account_id)->toBe($account->id);

    Storage::delete([$asset->path, $owned->path]);
});

test('post notes open after creation while the edit dialog still has its AI assistant', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
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
    $postWithoutNotes = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
    ]);
    PostPlatform::factory()->create(['post_id' => $postWithoutNotes->id, 'social_account_id' => $account->id]);
    $note = PostNote::factory()->create([
        'post_id' => $post->id,
        'user_id' => $user->id,
        'body' => 'Please review the opening line',
    ]);
    $this->actingAs($user);

    visit(route('app.posts.index', ['tab' => 'drafts']))
        ->assertVisible("@post-notes-filled-icon-{$post->id}")
        ->assertVisible("@post-notes-outline-icon-{$postWithoutNotes->id}");

    $page = visit(route('app.posts.index', ['notes' => $post->id, 'note' => $note->id]));
    $page->assertVisible("@post-notes-trigger-{$post->id}")
        ->assertSee('Please review the opening line');
    expect($page->script("document.querySelector('[data-testid=\"post-notes-trigger-{$post->id}\"]').textContent.trim()"))->toBe('');

    $layout = $page->script(<<<'JS'
        (() => {
            const row = document.querySelector('[data-testid^="post-card-"]');
            const card = row.querySelector('article').getBoundingClientRect();
            const notes = row.querySelector('[data-testid^="post-notes-trigger-"]').getBoundingClientRect();

            return {
                grid: getComputedStyle(row).display,
                notesBesideCard: notes.left >= card.right,
                hasTable: Boolean(document.querySelector('#posts-body table')),
            };
        })()
    JS);
    expect($layout)->toEqual([
        'grid' => 'grid',
        'notesBesideCard' => true,
        'hasTable' => false,
    ]);

    $page->click("@post-notes-trigger-{$post->id}");

    $page = visit(route('app.posts.edit', $post));
    $page->assertVisible('@post-composer-dialog')
        ->assertMissing('@composer-comments-panel')
        ->click('@composer-ai-assistant')
        ->assertVisible('@composer-assistant-panel')
        ->assertVisible('@writing-assistant-mode-rephrase')
        ->click('@composer-ai-assistant')
        ->assertVisible('@composer-assistant-panel')
        ->click('@composer-preview-toggle')
        ->assertVisible('@composer-previews-scroll')
        ->assertMissing('@composer-assistant-panel')
        ->click('@composer-preview-toggle')
        ->assertVisible('@composer-previews-scroll');
});

test('going back to the shared step asks first and discards the per-network edits', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $instagram = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $linkedin = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->fill('@composer-base-content', 'Shared first')
        ->click('@composer-add-account')->click("@composer-account-option-{$linkedin->id}")
        ->click("@composer-account-option-{$instagram->id}")
        ->click('@composer-next')
        ->fill("@composer-caption-{$instagram->id}", 'Only on Instagram')
        ->click('@composer-back')
        ->assertVisible('@composer-back-confirm')
        ->click('@composer-back-cancel')
        ->assertValue("@composer-caption-{$instagram->id}", 'Only on Instagram')
        ->click('@composer-back')
        ->click('@composer-back-confirm-go')
        ->assertValue('@composer-base-content', 'Shared first')
        ->assertMissing('@composer-back')
        ->fill('@composer-base-content', 'Shared second')
        ->click('@composer-next')
        ->assertValue("@composer-caption-{$instagram->id}", 'Shared second')
        ->click("@composer-expand-{$linkedin->id}")
        ->assertValue("@composer-caption-{$linkedin->id}", 'Shared second')
        ->fill("@composer-caption-{$linkedin->id}", 'LinkedIn only')
        ->click("@composer-remove-account-{$instagram->id}")
        ->assertValue("@composer-caption-{$linkedin->id}", 'LinkedIn only')
        ->click('@composer-add-account')->click("@composer-account-option-{$instagram->id}")
        ->assertValue('@composer-base-content', 'LinkedIn only')
        ->assertNoJavaScriptErrors();
});
test('X remaining characters use the same link defusing as its preview', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->click('@composer-add-account')->click("@composer-account-option-{$account->id}")
        ->fill("@composer-caption-{$account->id}", 'https://example.com/post')
        ->assertVisible("@composer-char-count-{$account->id}");

    expect(trim((string) $page->script("document.querySelector('[data-testid=\"composer-char-count-{$account->id}\"]').textContent")))
        ->toBe((string) (280 - mb_strlen('example(.)com/post')));
    $page->assertSee('example(.)com/post');
});

test('selecting a new image uploads an asset before any post is saved', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->click('@composer-add-account')->click("@composer-account-option-{$account->id}");

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const input = document.querySelector('[data-testid="composer-{$account->id}-file-input"]');
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'new-composer-image.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            for (let attempt = 0; attempt < 300; attempt++) {
                if (document.querySelector('[data-testid="composer-{$account->id}-media-item"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertVisible("@composer-{$account->id}-media-item");
    expect(Media::where('workspace_id', $workspace->id)->where('collection', Media::COLLECTION_UPLOADS)->count())->toBe(1)
        ->and(Post::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('dropping an image into the composer uploads it without saving a post', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->click('@composer-add-account')->click("@composer-account-option-{$account->id}");

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'dropped-image.png', { type: 'image/png' }));
            document.querySelector('[data-testid="composer-customization"]').dispatchEvent(
                new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }),
            );
            for (let attempt = 0; attempt < 300; attempt++) {
                if (document.querySelectorAll('[data-testid="composer-{$account->id}-media-item"]').length === 1) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->assertVisible("@composer-{$account->id}-media-item");
    expect(Media::where('workspace_id', $workspace->id)->where('collection', Media::COLLECTION_UPLOADS)->count())->toBe(1)
        ->and(Post::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('cropping in one network card creates a separate asset and leaves the other network image intact', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $accounts = [
        SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]),
        SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]),
    ];
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->fill('@composer-base-content', 'Image for both');

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const input = document.querySelector('[data-testid="composer-file-input"]');
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'crop-for-channel.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            for (let attempt = 0; attempt < 300; attempt++) {
                if (document.querySelector('[data-testid="composer-media-item"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->click('@composer-add-account');
    foreach ($accounts as $account) {
        $page->click("@composer-account-option-{$account->id}");
    }

    $originalId = Media::where('workspace_id', $workspace->id)->where('collection', Media::COLLECTION_UPLOADS)->sole()->id;
    $page->click('@composer-next')
        ->assertVisible("@composer-{$accounts[0]->id}-edit-0");

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

    $page->click("@composer-{$accounts[0]->id}-edit-0")
        ->click('@crop-aspect-1-1')
        ->click('@media-editor-apply');

    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                const save = document.querySelector('[data-testid="composer-save-draft"]');
                if (save && !save.disabled && !document.querySelector('[data-testid="media-editor-apply"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    $page->click('@composer-save-draft');

    $posts = Post::where('workspace_id', $workspace->id)->with('postPlatforms')->get()->keyBy(
        fn (Post $post) => $post->postPlatforms->sole()->social_account_id,
    );
    $uncropped = $posts[$accounts[1]->id]->ownedMedia()->sole();
    $cropped = $posts[$accounts[0]->id]->ownedMedia()->sole();
    expect($posts)->toHaveCount(2)
        ->and($cropped->upload_token)->toBeNull()
        ->and($uncropped->upload_token)->toBeNull()
        ->and($cropped->id)->not->toBe($originalId)
        ->and($uncropped->id)->toBe($originalId)
        ->and($cropped->path)->not->toBe($uncropped->path)
        ->and($posts[$accounts[1]->id]->media[0]['id'])->toBe($uncropped->id);
});

test('animated GIFs and videos do not offer the static image crop action', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
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
        ->assertMissing("@composer-{$account->id}-edit-0")
        ->assertMissing("@composer-{$account->id}-edit-1");
});

test('video thumbnails appear in shared and channel media', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $video = base64_encode((string) file_get_contents(base_path('tests/fixtures/sample.mp4')));
    $page->script(<<<JS
        (async () => {
            const input = document.querySelector('[data-testid="composer-file-input"]');
            const bytes = Uint8Array.from(atob('{$video}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'sample.mp4', { type: 'video/mp4' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            for (let attempt = 0; attempt < 300; attempt++) {
                if (document.querySelector('[data-testid="composer-media-item"] video')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    $source = $page->script('document.querySelector("[data-testid=composer-media-item] video")?.getAttribute("src")');
    expect($source)->toBeString()->not->toBeEmpty();

    $page->click('@composer-add-account')->click("@composer-account-option-{$account->id}");
    expect($page->script("document.querySelector('[data-testid=\"composer-{$account->id}-media-item\"] video')?.getAttribute('src')"))->toBe($source);
    $page->assertMissing('@instagram-aspect-original')
        ->assertMissing('@instagram-aspect-1-1')
        ->assertNoJavaScriptErrors();
});

test('failed crop upload keeps the original asset selected', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::X]);
    $asset = Media::factory()->temporaryUpload($workspace)->create(['mime_type' => 'image/png', 'original_filename' => 'original.png']);
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
            const { open, send } = XMLHttpRequest.prototype;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__url = String(url);
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (! this.__url.includes('/media/chunked')) return send.call(this, body);
                window.__cropUploadFailed = true;
                Object.defineProperty(this, 'status', { value: 500 });
                Object.defineProperty(this, 'responseText', { value: 'failed' });
                setTimeout(() => this.dispatchEvent(new ProgressEvent('load')));
            };
        })();
    JS);

    waitForComposerReady($page, "composer-{$account->id}-edit-0");
    $page->click("@composer-{$account->id}-edit-0")
        ->click('@crop-aspect-1-1')
        ->click('@media-editor-apply');
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if (window.__cropUploadFailed && document.querySelector('[data-testid="composer-save-draft"]')?.disabled === false) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    expect($page->script('Boolean(window.__cropUploadFailed)'))->toBeTrue();
    $page->click('@composer-save-draft');

    expect($post->fresh()->media[0]['id'])->toBe($asset->id)
        ->and(Media::query()->whereKey($asset->id)->exists())->toBeTrue();
});

test('settled legacy multi-target history appears as one read-only card per target', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'status' => PostStatus::Published]);
    $accounts = SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    foreach ($accounts as $account) {
        PostPlatform::factory()->published()->create(['post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::LinkedIn, 'enabled' => true]);
    }
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'sent']));
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if (document.querySelectorAll('[data-testid^="post-card-"]:not([data-testid^="post-card-menu-"])').length === 2) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
    expect($page->script('document.querySelectorAll("[data-testid^=post-card-]:not([data-testid^=post-card-menu-])").length'))->toBe(2)
        ->and(Post::where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('customizing several channels uses plural actions and flags the collapsed channel that needs fixing', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page);
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$instagram->id}")
        ->fill('@composer-base-content', 'Text only')
        ->click('@composer-next')
        ->assertSee('Save drafts')
        ->assertSee('Publish posts')
        ->assertVisible("@composer-issues-{$instagram->id}")
        ->assertSeeIn("@composer-issues-{$instagram->id}", '1')
        ->assertDisabled('@composer-submit')
        ->assertEnabled('@composer-save-draft')
        ->assertNoJavaScriptErrors();
});

test('a persisted image in the composer offers no AI image adjustment', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $asset = Media::factory()->temporaryUpload($workspace)->create(['mime_type' => 'image/png', 'original_filename' => 'original.png']);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'A draft with an image',
        'media' => [MediaItem::fromMedia($asset)->toArray()],
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'enabled' => true,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));
    waitForComposerReady($page, "composer-{$account->id}-edit-0");

    $page->assertVisible("@composer-{$account->id}-edit-0")
        ->assertMissing("@composer-ai-regenerate-{$account->id}-0")
        ->assertNoJavaScriptErrors();
});

test('the composer asks to connect a channel when the workspace has none', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'user_id' => $user->id,
        'account_id' => $user->account_id,
    ]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerReady($page, 'composer-connect-channel');

    $page->assertSeeIn('@composer-connect-channel', __('posts.composer.connect_to_post'))
        ->assertMissing('@composer-submit')
        ->assertMissing('@composer-schedule-trigger')
        ->click('@composer-connect-channel');
    waitForComposerReady($page, 'connect-channel-dialog');

    $page->assertVisible('@connect-channel-dialog')->assertNoJavaScriptErrors();
});
