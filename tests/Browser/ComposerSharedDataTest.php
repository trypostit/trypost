<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Http\Client\Request;
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
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $workspace->user_id,
        'status' => PostStatus::Scheduled,
        'content' => 'Shared data post',
        'scheduled_at' => now('UTC')->addMonthNoOverflow()->startOfMonth()->addDays(10)->setTime(10, 0),
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $account->platform,
        'content_type' => $contentType,
        'meta' => $meta,
    ]);

    return $post;
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

function pinterestBoardListReads(): int
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'GET'
        && str_starts_with($request->url(), config('trypost.platforms.pinterest.api').'/boards'))->count();
}

test('a new post lists every channel at once and fills the pinterest and tiktok pickers', function () {
    [$user, , $pinterest, $tiktok] = composerSharedDataSetup();
    $this->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerSharedDataReady($page, 'composer-add-account');

    $page->click('@composer-add-account')
        ->assertVisible("@composer-account-option-{$pinterest->id}")
        ->assertVisible("@composer-account-option-{$tiktok->id}")
        ->click("@composer-account-option-{$pinterest->id}");
    waitForComposerSharedDataTestId($page, 'pinterest-board-trigger');

    $page->click('@pinterest-board-trigger');
    waitForComposerSharedDataTestId($page, 'pinterest-board-option-board_live');
    $page->assertSeeIn('@pinterest-board-option-board_live', 'Live board')
        ->assertNoJavaScriptErrors();
});

test('the composer opens again from the sidebar without reading pinterest again', function () {
    [$user, , $pinterest] = composerSharedDataSetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    clickComposerSharedDataNewPost($page);
    waitForComposerSharedDataReady($page, 'composer-add-account');
    $page->click('@composer-close');
    waitForComposerSharedDataCondition($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')");

    clickComposerSharedDataNewPost($page);
    waitForComposerSharedDataReady($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->assertVisible("@composer-account-option-{$pinterest->id}")
        ->assertNoJavaScriptErrors();

    expect(pinterestBoardListReads())->toBeLessThanOrEqual(1);
});

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
    $page->click('@sidebar-new');
    waitForComposerSharedDataTestId($page, 'sidebar-new-post');
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
    $post = Post::factory()->draft()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'content' => 'TikTok draft']);
    $target = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $tiktok->id,
        'platform' => Platform::TikTok,
        'content_type' => ContentType::TikTokVideo,
        'meta' => ['privacy_level' => 'SELF_ONLY', 'allow_comments' => true],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['edit' => $post->id]));
    waitForComposerSharedDataTestId($page, 'tiktok-allow-comments');
    $page->click('@composer-save-draft');
    waitForComposerSharedDataCondition($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')");

    expect(data_get($target->fresh()->meta, 'allow_comments'))->toBeTrue();
});

/**
 * A LinkedIn channel whose only posting time today is 15:00 in a zone where it
 * is noon now, so the slot is in the future whatever the test clock says.
 */
function composerSharedDataSlotChannel(Workspace $workspace): SocialAccount
{
    $offset = 12 - now('UTC')->hour;
    $zone = match (true) {
        $offset === 0 => 'UTC',
        $offset > 0 => "Etc/GMT-{$offset}",
        default => 'Etc/GMT+'.abs($offset),
    };

    return SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'timezone' => $zone,
        'posting_schedule' => PostingSchedule::empty()->withTime(now($zone)->dayOfWeek, '15:00'),
    ]);
}

/**
 * Keeps every request to the TikTok channel's composer data from being sent
 * until `window.__releaseTikTok()` runs.
 */
function holdComposerSharedDataTikTok(mixed $page, SocialAccount $tiktok): void
{
    $page->script(<<<JS
        (() => {
            const { open, send } = XMLHttpRequest.prototype;
            const held = [];
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__held = String(url).includes('/posts/composer/accounts/{$tiktok->id}');
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (this.__held && !window.__tiktokReleased) {
                    held.push(() => send.call(this, body));
                    return;
                }
                return send.call(this, body);
            };
            window.__releaseTikTok = () => {
                window.__tiktokReleased = true;
                held.splice(0).forEach((release) => release());
            };
        })()
    JS);
}

test('a network that does not answer holds only its own channel settings', function () {
    [$user, , $pinterest, $tiktok] = composerSharedDataSetup();
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    holdComposerSharedDataTikTok($page, $tiktok);
    openComposerFromSidebar($page);

    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$pinterest->id}");
    waitForComposerSharedDataTestId($page, 'pinterest-board-trigger');
    $page->assertMissing('@composer-live-data-pending');

    $page->click("@composer-account-option-{$pinterest->id}")
        ->click("@composer-account-option-{$tiktok->id}");
    waitForComposerSharedDataTestId($page, 'composer-live-data-pending');
    $page->assertMissing('@tiktok-privacy-level');

    $page->script('window.__releaseTikTok()');
    waitForComposerSharedDataTestId($page, 'tiktok-privacy-level');
    $page->assertMissing('@composer-live-data-pending')
        ->assertNoJavaScriptErrors();
});

test('a failed slot read shows a retry banner and slots open once it loads', function () {
    [$user, $workspace] = composerSharedDataSetup();
    $channel = composerSharedDataSlotChannel($workspace);
    $this->actingAs($user);

    $page = visit(route('app.insights'));
    $page->script(<<<'JS'
        (() => {
            const open = XMLHttpRequest.prototype.open;
            let broken = false;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                if (!broken && String(url).includes('/taken-slots')) {
                    broken = true;
                    url = String(url).replace('/taken-slots', '/taken-slots-unavailable');
                }
                return open.call(this, method, url, ...rest);
            };
        })()
    JS);
    openComposerFromSidebar($page);

    composerSharedDataZoneLabel($page, $channel);
    waitForComposerSharedDataTestId($page, 'composer-live-data-failed');
    $page->assertSeeIn('@composer-live-data-failed', __('posts.composer.load_failed'));
    waitForComposerSharedDataTestId($page, 'composer-schedule-slot-1500');
    expect($page->script('document.querySelector("[data-testid=composer-schedule-slot-1500]").disabled'))->toBeTrue();

    $page->click('@composer-live-data-retry');
    waitForComposerSharedDataCondition($page, "!document.querySelector('[data-testid=\"composer-live-data-failed\"]') && document.querySelector('[data-testid=\"composer-schedule-slot-1500\"]')?.disabled === false");

    $page->assertMissing('@composer-live-data-failed');
    expect($page->script('document.querySelector("[data-testid=composer-schedule-slot-1500]").disabled'))->toBeFalse();
});
