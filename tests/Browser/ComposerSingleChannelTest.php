<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * @return array{0: User, 1: Workspace}
 */
function singleChannelComposerWorkspace(): array
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

/**
 * The composer opens as an animated dialog and swaps editors when the channel
 * count changes. Poll from the page (never sleep()) until the element is laid out.
 */
function waitForSingleChannelComposerTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
                if (sheet?.getAttribute('data-state') === 'open'
                    && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                    && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForSingleChannelComposerClosed(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80; attempt++) {
                if (!document.querySelector('[data-testid="post-composer-dialog"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

test('one channel opens its own editor directly and a second channel returns to the shared step', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $youtube = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForSingleChannelComposerTestId($page, 'composer-add-account');
    $page->fill('@composer-base-content', 'Typed before choosing')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$youtube->id}")
        ->assertVisible('@composer-customization')
        ->assertValue("@composer-caption-{$youtube->id}", 'Typed before choosing')
        ->assertVisible('@youtube-title')
        ->assertVisible('@composer-submit')
        ->assertMissing('@composer-next')
        ->assertMissing('@composer-back')
        ->assertMissing('@composer-base-content');
    expect($page->script('document.querySelectorAll("[data-testid=composer-preview-frame]").length'))->toBe(1);

    $page->fill('@youtube-title', 'Only for YouTube')
        ->fill("@composer-caption-{$youtube->id}", 'Edited for one channel')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$linkedin->id}")
        ->assertValue('@composer-base-content', 'Edited for one channel')
        ->assertVisible('@composer-next')
        ->assertMissing('@composer-customization');
    expect($page->script('document.querySelectorAll("[data-testid=composer-preview-card]").length'))->toBe(2);

    $page->click('@composer-next')
        ->click("@composer-account-{$youtube->id}")
        ->assertValue("@composer-caption-{$youtube->id}", 'Edited for one channel')
        ->assertValue('@youtube-title', 'Only for YouTube')
        ->click("@composer-account-{$linkedin->id}")
        ->fill("@composer-caption-{$linkedin->id}", 'Only for LinkedIn')
        ->click("@composer-remove-account-{$youtube->id}")
        ->assertVisible('@composer-customization')
        ->assertValue("@composer-caption-{$linkedin->id}", 'Only for LinkedIn')
        ->assertMissing('@composer-next')
        ->assertMissing('@composer-back')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$youtube->id}")
        ->assertValue('@composer-base-content', 'Only for LinkedIn')
        ->click("@composer-remove-account-{$youtube->id}")
        ->assertValue("@composer-caption-{$linkedin->id}", 'Only for LinkedIn')
        ->assertNoJavaScriptErrors();
});

test('saving a single channel draft keeps its network fields on the post platform', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $youtube = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForSingleChannelComposerTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$youtube->id}")
        ->fill("@composer-caption-{$youtube->id}", 'A short about the launch')
        ->fill('@youtube-title', 'Launch title')
        ->click('@composer-save-draft');
    waitForSingleChannelComposerClosed($page);

    $post = Post::where('workspace_id', $workspace->id)->sole();
    $platform = $post->postPlatforms()->sole();
    expect($post->status)->toBe(PostStatus::Draft)
        ->and($post->content)->toBe('A short about the launch')
        ->and($platform->social_account_id)->toBe($youtube->id)
        ->and($platform->content_type)->toBe(ContentType::YouTubeShort)
        ->and(data_get($platform->meta, 'title'))->toBe('Launch title');
});

test('picking youtube after typing the caption pre-fills the title from it', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $youtube = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForSingleChannelComposerTestId($page, 'composer-add-account');
    $page->fill('@composer-base-content', 'Behind the scenes of our launch')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$youtube->id}")
        ->assertValue('@youtube-title', 'Behind the scenes of our launch')
        ->assertNoJavaScriptErrors();
});

test('the youtube title follows the caption until the title is edited', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $youtube = SocialAccount::factory()->youtube()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForSingleChannelComposerTestId($page, 'composer-add-account');
    $page->fill('@composer-base-content', "Typed first\nMore details below")
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$youtube->id}")
        ->assertValue('@youtube-title', 'Typed first')
        ->fill("@composer-caption-{$youtube->id}", "\n\nLaunch day\nMore details below")
        ->assertValue('@youtube-title', 'Launch day')
        ->fill("@composer-caption-{$youtube->id}", str_repeat('a', 120))
        ->assertValue('@youtube-title', str_repeat('a', 100))
        ->fill("@composer-caption-{$youtube->id}", 'Video <b>tips</b>')
        ->assertValue('@youtube-title', 'Video tips')
        ->fill("@composer-caption-{$youtube->id}", '<p></p><p>First &amp; best</p><p>Second</p>')
        ->assertValue('@youtube-title', 'First & best')
        ->fill('@youtube-title', 'My own title')
        ->fill("@composer-caption-{$youtube->id}", 'Changed caption')
        ->assertValue('@youtube-title', 'My own title')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft');
    waitForSingleChannelComposerClosed($page);

    $platform = Post::where('workspace_id', $workspace->id)->sole()->postPlatforms()->sole();
    expect(data_get($platform->meta, 'title'))->toBe('My own title');
});

test('scheduling a single channel saves its post platform with the chosen format', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $facebook = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Facebook]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForSingleChannelComposerTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$facebook->id}")
        ->assertVisible("@composer-type-{$facebook->id}")
        ->fill("@composer-caption-{$facebook->id}", 'Scheduled for one page')
        ->click('@composer-schedule-trigger')
        ->click('@composer-schedule-custom');
    waitForSingleChannelComposerTestId($page, 'composer-schedule-picker');

    $query = '[...document.querySelectorAll("[data-testid^=composer-schedule-day-]")].find((day) => !day.hasAttribute("data-disabled") && !day.hasAttribute("data-outside-view") && !day.hasAttribute("data-zone-today"))?.dataset.testid.replace("composer-schedule-day-", "") ?? null';
    $day = $page->script($query);
    if ($day === null) {
        $page->click('@composer-schedule-calendar-next');
        $day = $page->script($query);
    }
    $page->click("@composer-schedule-day-{$day}")
        ->fill('@composer-schedule-time-input', '1715')
        ->click('@composer-schedule-done')
        ->click('@composer-submit');
    waitForSingleChannelComposerClosed($page);

    $post = Post::where('workspace_id', $workspace->id)->sole();
    $platform = $post->postPlatforms()->sole();
    expect($post->status)->toBe(PostStatus::Scheduled)
        ->and($post->schedule_mode)->toBe(ScheduleMode::Custom)
        ->and($post->scheduled_at)->not->toBeNull()
        ->and($post->content)->toBe('Scheduled for one page')
        ->and($platform->social_account_id)->toBe($facebook->id)
        ->and($platform->platform)->toBe(Platform::Facebook)
        ->and($platform->content_type)->toBe(ContentType::FacebookPost);
});

test('editing a single channel post opens its own editor', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'status' => PostStatus::Draft,
        'content' => 'Already written',
    ]);
    PostPlatform::factory()->linkedin()->create(['post_id' => $post->id, 'social_account_id' => $account->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));
    waitForSingleChannelComposerTestId($page, 'composer-customization');
    $page->assertValue("@composer-caption-{$account->id}", 'Already written')
        ->assertVisible('@composer-submit')
        ->assertMissing('@composer-next')
        ->assertMissing('@composer-back')
        ->assertMissing('@composer-add-account')
        ->assertNoJavaScriptErrors();
});

test('text only channels start on the shared step and every card starts from the shared text', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $facebook = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Facebook]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForSingleChannelComposerTestId($page, 'composer-add-account');
    $page->fill('@composer-base-content', 'Shared idea')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$facebook->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->click("@composer-account-option-{$x->id}")
        ->assertValue('@composer-base-content', 'Shared idea')
        ->assertMissing('@composer-customization')
        ->assertSeeIn('@composer-save-draft', 'Save drafts')
        ->assertMissing('@composer-submit')
        ->assertMissing('@composer-back');
    expect($page->script('document.querySelectorAll("[data-testid=composer-preview-card]").length'))->toBe(3);

    $page->click('@composer-next');
    waitForSingleChannelComposerTestId($page, "composer-caption-{$x->id}");
    $page->assertValue("@composer-caption-{$x->id}", 'Shared idea')
        ->assertSeeIn("@composer-expand-{$linkedin->id}", 'Shared idea')
        ->assertSeeIn("@composer-expand-{$facebook->id}", 'Shared idea')
        ->assertSeeIn('@composer-submit', 'Publish posts')
        ->assertVisible('@composer-back')
        ->assertMissing('@composer-next')
        ->assertMissing('@composer-base-content');
    expect($page->script('document.querySelectorAll("[data-testid=composer-preview-frame]").length'))->toBe(1);

    $page->fill("@composer-caption-{$x->id}", 'Only on X')
        ->assertSeeIn("@composer-expand-{$linkedin->id}", 'Shared idea')
        ->click("@composer-remove-account-{$facebook->id}")
        ->assertValue("@composer-caption-{$x->id}", 'Only on X')
        ->click("@composer-remove-account-{$x->id}")
        ->assertValue("@composer-caption-{$linkedin->id}", 'Shared idea')
        ->assertMissing('@composer-back')
        ->assertNoJavaScriptErrors();
});

test('shared media keeps the shared step before customizing each network', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForSingleChannelComposerTestId($page, 'composer-add-account');
    $page->fill('@composer-base-content', 'With a picture');

    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (async () => {
            const input = document.querySelector('[data-testid="composer-file-input"]');
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'shared.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
            for (let attempt = 0; attempt < 100; attempt++) {
                if (document.querySelector('[data-testid="composer-media-item-0"]')) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);

    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->click("@composer-account-option-{$linkedin->id}")
        ->assertValue('@composer-base-content', 'With a picture')
        ->assertVisible('@composer-media-item-0')
        ->assertVisible('@composer-next')
        ->assertMissing('@composer-customization')
        ->click('@composer-next')
        ->assertVisible('@composer-customization')
        ->assertVisible('@composer-back')
        ->assertNoJavaScriptErrors();
});

test('a channel that needs media keeps the shared step and shows the requirement', function () {
    [$user, $workspace] = singleChannelComposerWorkspace();
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForSingleChannelComposerTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$x->id}")
        ->fill("@composer-caption-{$x->id}", 'Text only')
        ->click('@composer-add-account')
        ->click("@composer-account-option-{$instagram->id}")
        ->assertValue('@composer-base-content', 'Text only')
        ->assertVisible('@composer-next')
        ->assertMissing('@composer-customization')
        ->click('@composer-next')
        ->click("@composer-account-{$instagram->id}")
        ->assertVisible("@composer-media-warning-{$instagram->id}")
        ->assertNoJavaScriptErrors();
});
