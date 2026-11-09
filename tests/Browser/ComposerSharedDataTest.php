<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

/**
 * @return array{0: User, 1: Workspace, 2: SocialAccount, 3: SocialAccount}
 */
function composerSharedDataSetup(): array
{
    $user = User::factory()->create(['timezone' => 'UTC']);
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    $pinterest = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $workspace->id,
        'username' => 'pinner',
        'timezone' => 'UTC',
        'token_expires_at' => now()->addDays(20),
    ]);
    $tiktok = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $workspace->id,
        'username' => 'tiktoker',
        'timezone' => 'UTC',
        'token_expires_at' => now()->addDays(20),
    ]);

    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => Http::response([
            'items' => [['id' => 'board_live', 'name' => 'Live board', 'media' => ['image_cover_url' => null]]],
            'bookmark' => null,
        ]),
        config('trypost.platforms.tiktok.api').'/post/publish/creator_info/query/' => Http::response(['data' => [
            'creator_nickname' => 'Tik',
            'creator_username' => 'tiktoker',
            'privacy_level_options' => ['SELF_ONLY'],
            'comment_disabled' => false,
            'duet_disabled' => false,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => 600,
        ]]),
    ]);

    return [$user, $workspace, $pinterest, $tiktok];
}

function composerSharedDataPost(Workspace $workspace, SocialAccount $account, ContentType $contentType, array $meta = []): Post
{
    return Post::factory()->forAccount($account, $contentType)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $workspace->user_id,
        'status' => PostStatus::Scheduled,
        'content' => 'Shared data post',
        'scheduled_at' => now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(10)->setTime(10, 0),
        'meta' => $meta,
    ]);
}

test('editing from the calendar opens the post with its board', function () {
    [$user, $workspace, $pinterest] = composerSharedDataSetup();
    $post = composerSharedDataPost($workspace, $pinterest, ContentType::PinterestPin, ['board_id' => 'board_live']);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $post->scheduled_at->format('Y-m-d')]));
    waitFor($page, "@calendar-post-{$post->id}");
    $page->click("@calendar-post-{$post->id}");
    waitFor($page, "@post-details-edit-{$post->id}");
    $page->click("@post-details-edit-{$post->id}");
    waitFor($page, '@pinterest-board-selected');

    $page->assertSeeIn('@pinterest-board-selected', 'Live board')
        ->assertNoJavaScriptErrors();
});

function clickComposerSharedDataNewPost(mixed $page): void
{
    $menuOpen = "Boolean(document.querySelector('[data-testid=\"sidebar-new-menu\"][data-state=\"open\"]'))";

    for ($attempt = 0; $attempt < 3 && ! $page->script($menuOpen); $attempt++) {
        $page->click('@sidebar-new');
        waitForState($page, '@sidebar-new-menu[data-state="open"]', 'true');
    }

    waitFor($page, '@sidebar-new-post');
    $page->click('@sidebar-new-post');
}

function openComposerFromSidebar(mixed $page): void
{
    clickComposerSharedDataNewPost($page);
    waitForOpen($page, '@post-composer-dialog');
    waitFor($page, '@composer-add-account');
}

function closeComposerSharedData(mixed $page): void
{
    $page->click('@composer-close');
    waitForGone($page, '@post-composer-dialog');
}

function composerSharedDataZoneLabel(mixed $page, SocialAccount $channel): string
{
    $page->click('@composer-add-account');
    waitFor($page, "@composer-account-option-{$channel->id}");
    $page->click("@composer-account-option-{$channel->id}");
    waitFor($page, '@composer-schedule-trigger');
    $page->click('@composer-schedule-trigger');
    waitForState($page, '@composer-schedule-picker, @composer-schedule-custom', 'true');

    if (! $page->script('Boolean(document.querySelector(\'[data-testid="composer-schedule-picker"]\'))')) {
        $page->click('@composer-schedule-custom');
    }

    waitFor($page, '@composer-schedule-timezone');

    return trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-timezone]").textContent'));
}

test('the next composer open shows a channel time zone the user just changed without a page visit', function () {
    [$user, $workspace] = composerSharedDataSetup();
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openComposerFromSidebar($page);
    expect(composerSharedDataZoneLabel($page, $channel))->toContain('UTC');
    closeComposerSharedData($page);

    $channel->update(['timezone' => 'Asia/Tokyo']);

    clickComposerSharedDataNewPost($page);
    waitFor($page, '@composer-resume-discard');
    $page->click('@composer-resume-discard');
    waitForOpen($page, '@post-composer-dialog');
    waitFor($page, '@composer-add-account');
    composerSharedDataZoneLabel($page, $channel);
    waitForText($page, '@composer-schedule-timezone', 'Tokyo');

    expect(trim((string) $page->script('document.querySelector("[data-testid=composer-schedule-timezone]").textContent')))->toContain('Tokyo');
    $page->assertNoJavaScriptErrors();
});

test('a signature created in the composer is there when the composer opens again', function () {
    [$user, $workspace] = composerSharedDataSetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    openComposerFromSidebar($page);
    $page->click('@composer-base-signature')
        ->click('@composer-signatures-empty-create')
        ->fill('@composer-signature-name', 'Launch tags')
        ->fill('@composer-signature-content', '#launch')
        ->click('@submit-composer-signature')
        ->assertSee('Launch tags');
    closeComposerSharedData($page);

    openComposerFromSidebar($page);
    $page->click('@composer-base-signature');
    waitForText($page, '@composer-signatures-popover', 'Launch tags');

    $page->assertSeeIn('@composer-signatures-popover', 'Launch tags')
        ->assertNoJavaScriptErrors();
    expect($workspace->signatures()->where('name', 'Launch tags')->exists())->toBeTrue();
});

test('editing a tiktok post keeps its stored interaction flags', function () {
    [$user, $workspace, , $tiktok] = composerSharedDataSetup();
    Http::fake([
        config('trypost.platforms.tiktok.api').'/post/publish/creator_info/query/' => Http::response(['data' => [
            'creator_nickname' => 'Tik',
            'creator_username' => 'tiktoker',
            'privacy_level_options' => ['SELF_ONLY'],
            'comment_disabled' => true,
            'duet_disabled' => false,
            'stitch_disabled' => false,
            'max_video_post_duration_sec' => 600,
        ]]),
    ]);
    $post = Post::factory()->forAccount($tiktok, ContentType::TikTokVideo)->draft()->create([
        'user_id' => $user->id,
        'content' => 'TikTok draft',
        'meta' => ['privacy_level' => 'SELF_ONLY', 'allow_comments' => true],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['edit' => $post->id]));
    waitFor($page, '@tiktok-allow-comments');
    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect(data_get($post->fresh()->meta, 'allow_comments'))->toBeTrue();
});

test('editing a post shows a server media error on the item it belongs to', function () {
    [$user, $workspace] = composerSharedDataSetup();
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id, 'timezone' => 'UTC']);
    $post = Post::factory()->forAccount($linkedin, ContentType::LinkedInPost)->draft()->create(['user_id' => $user->id, 'content' => 'Two images']);
    $media = collect(['kept.png', 'gone.png'])->map(fn (string $name): Media => Media::factory()->create([
        'workspace_id' => $workspace->id,
        'post_id' => $post->id,
        'mediable_type' => null,
        'mediable_id' => null,
        'collection' => Media::COLLECTION_MEDIA,
        'original_filename' => $name,
        'mime_type' => 'image/png',
    ]));
    $post->update(['media' => $media->map(fn (Media $item): array => [
        'id' => $item->id,
        'path' => $item->path,
        'url' => $item->url,
        'type' => 'image',
        'mime_type' => 'image/png',
        'original_filename' => $item->original_filename,
        'meta' => ['width' => 1200, 'height' => 1200],
    ])->all()]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['edit' => $post->id]));
    waitForCount($page, '[data-testid$="-media-item"]', 2);
    $media->last()->delete();

    $page->click('@composer-save-draft');
    waitForState($page, '[data-testid$="-media-error-1"]', 'true');

    expect($page->script("document.querySelector('[data-testid\$=\"-media-error-0\"]')"))->toBeNull();
    $page->assertNoJavaScriptErrors();
});
