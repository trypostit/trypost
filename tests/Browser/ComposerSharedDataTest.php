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

function waitForComposerSharedDataCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 300; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForComposerSharedDataTestId(mixed $page, string $testId): void
{
    waitForComposerSharedDataCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

function waitForComposerSharedDataReady(mixed $page, string $testId): void
{
    waitForComposerSharedDataCondition($page, <<<JS
        (() => {
            const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
            return sheet?.getAttribute('data-state') === 'open'
                && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                && document.querySelector('[data-testid="{$testId}"]')?.getBoundingClientRect().height > 0;
        })()
    JS);
}

test('editing from the calendar opens the post with its board', function () {
    [$user, $workspace, $pinterest] = composerSharedDataSetup();
    $post = composerSharedDataPost($workspace, $pinterest, ContentType::PinterestPin, ['board_id' => 'board_live']);
    $this->actingAs($user);

    $page = visit(route('app.calendar', ['view' => 'month', 'month' => $post->scheduled_at->format('Y-m-d')]));
    waitForComposerSharedDataTestId($page, "calendar-post-{$post->id}");
    $page->click("@calendar-post-{$post->id}");
    waitForComposerSharedDataTestId($page, "post-edit-{$post->id}");
    $page->click("@post-edit-{$post->id}");
    waitForComposerSharedDataTestId($page, 'pinterest-board-selected');

    $page->assertSeeIn('@pinterest-board-selected', 'Live board')
        ->assertNoJavaScriptErrors();
});

function clickComposerSharedDataNewPost(mixed $page): void
{
    $item = "document.querySelector('[data-testid=\"sidebar-new-post\"]')?.getBoundingClientRect().height > 0";

    for ($attempt = 0; $attempt < 3 && ! $page->script("Boolean({$item})"); $attempt++) {
        $page->click('@sidebar-new');
        waitForComposerSharedDataCondition($page, $item);
    }

    $page->click('@sidebar-new-post');
}

function openComposerFromSidebar(mixed $page): void
{
    clickComposerSharedDataNewPost($page);
    waitForComposerSharedDataReady($page, 'composer-add-account');
}

function closeComposerSharedData(mixed $page): void
{
    $page->click('@composer-close');
    waitForComposerSharedDataCondition($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')");
}

function composerSharedDataZoneLabel(mixed $page, SocialAccount $channel): string
{
    $page->click('@composer-add-account');
    waitForComposerSharedDataTestId($page, "composer-account-option-{$channel->id}");
    $page->click("@composer-account-option-{$channel->id}");
    waitForComposerSharedDataTestId($page, 'composer-schedule-trigger');
    $page->click('@composer-schedule-trigger');
    waitForComposerSharedDataCondition($page, "document.querySelector('[data-testid=\"composer-schedule-picker\"]') || document.querySelector('[data-testid=\"composer-schedule-custom\"]')");

    if (! $page->script('Boolean(document.querySelector(\'[data-testid="composer-schedule-picker"]\'))')) {
        $page->click('@composer-schedule-custom');
    }

    waitForComposerSharedDataTestId($page, 'composer-schedule-timezone');

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
    waitForComposerSharedDataTestId($page, 'composer-resume-discard');
    $page->click('@composer-resume-discard');
    waitForComposerSharedDataReady($page, 'composer-add-account');
    composerSharedDataZoneLabel($page, $channel);
    waitForComposerSharedDataCondition($page, "document.querySelector('[data-testid=\"composer-schedule-timezone\"]')?.textContent.includes('Tokyo')");

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
    waitForComposerSharedDataCondition($page, "document.querySelector('[data-testid=\"composer-signatures-popover\"]')?.textContent.includes('Launch tags')");

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
    waitForComposerSharedDataTestId($page, 'tiktok-allow-comments');
    $page->click('@composer-save-draft');
    waitForComposerSharedDataCondition($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')");

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
    waitForComposerSharedDataCondition($page, "document.querySelectorAll('[data-testid\$=\"-media-item\"]').length === 2");
    $media->last()->delete();

    $page->click('@composer-save-draft');
    waitForComposerSharedDataCondition($page, "document.querySelector('[data-testid\$=\"-media-error-1\"]') !== null");

    expect($page->script("document.querySelector('[data-testid\$=\"-media-error-0\"]')"))->toBeNull();
    $page->assertNoJavaScriptErrors();
});
