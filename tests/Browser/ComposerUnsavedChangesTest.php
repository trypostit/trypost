<?php

declare(strict_types=1);

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\Discord\DiscordClient;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

function seedUnsavedChangesReel(): Post
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Instagram]);
    $post = Post::factory()->forAccount($account, ContentType::InstagramReel)->create([
        'user_id' => $user->id,
        'content' => 'Unsaved changes',
        'meta' => [],
    ]);
    test()->actingAs($user);

    return $post;
}

test('closing an edited post without changes asks nothing', function () {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->click('@composer-close');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertNoJavaScriptErrors();
});

test('closing an edited post with unsaved changes asks first, keeps it open on cancel and drops the changes on discard', function () {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->click('@instagram-share-to-feed')
        ->click('@composer-close');
    waitForTestId($page, 'unsaved-changes-dialog');

    $page->click('@unsaved-changes-keep');
    waitForTestIdGone($page, 'unsaved-changes-dialog');
    $page->assertVisible('@post-composer-dialog')
        ->click('@composer-close');
    waitForTestId($page, 'unsaved-changes-discard');
    $page->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@post-composer-dialog')->assertNoJavaScriptErrors();
    expect($post->fresh()->meta)->toBe([]);
});

test('saving an edited post closes it without asking', function () {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->click('@instagram-share-to-feed')
        ->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@unsaved-changes-dialog')->assertNoJavaScriptErrors();
    expect(data_get($post->fresh()->meta, 'share_to_feed'))->toBeFalse();
});

test('after saving an edited post the list shows the saved post and one back leaves it for the page before', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post, route('app.settings.preferences'));

    $page->fill("@composer-caption-{$post->social_account_id}", 'Saved from the composer')
        ->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');
    waitForCondition($page, "!location.search.includes('edit=')");
    waitForTestIdState($page, "post-card-{$post->id}", "element.textContent.includes('Saved from the composer')");

    $page->assertPathIs(unsavedChangesListPath())
        ->assertSeeIn("@post-card-{$post->id}", 'Saved from the composer');
    $page->back();
    waitForPath($page, parse_url(route('app.settings.preferences'), PHP_URL_PATH));

    $page->assertPathIs((string) parse_url(route('app.settings.preferences'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
    expect($post->fresh()->content)->toContain('Saved from the composer');
});

test('a query parameter added to the edit entry after it opened still lets one back after saving reach the page before', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post, route('app.settings.preferences'));
    $page->script("window.history.replaceState(window.history.state, '', window.location.href + '&queue_days=14')");

    $page->fill("@composer-caption-{$post->social_account_id}", 'Saved after a partial reload')
        ->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');
    waitForCondition($page, "!location.search.includes('edit=')");
    waitForTestIdState($page, "post-card-{$post->id}", "element.textContent.includes('Saved after a partial reload')");
    $page->back();
    waitForPath($page, parse_url(route('app.settings.preferences'), PHP_URL_PATH));

    $page->assertPathIs((string) parse_url(route('app.settings.preferences'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
});

test('closing an untouched post with X then one back leaves the list for the page before', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post, route('app.settings.preferences'));

    $page->click('@composer-close');
    waitForTestIdGone($page, 'post-composer-dialog');
    waitForCondition($page, "!location.search.includes('edit=')");
    $page->assertPathIs(unsavedChangesListPath())->assertMissing('@unsaved-changes-dialog');

    $page->back();
    waitForPath($page, parse_url(route('app.settings.preferences'), PHP_URL_PATH));

    $page->assertPathIs((string) parse_url(route('app.settings.preferences'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
});

test('a post opened by its edit link saves and closes onto the list without stepping back', function (string $leave) {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->script(<<<'JS'
        (() => {
            window.__backs = 0;
            window.__entries = history.length;
            const back = window.history.back.bind(window.history);
            window.history.back = () => { window.__backs++; back(); };
        })()
    JS);

    $page->click($leave === 'save' ? '@composer-save-draft' : '@composer-close');
    waitForTestIdGone($page, 'post-composer-dialog');
    waitForCondition($page, "!location.search.includes('edit=')");
    $page->script('new Promise((resolve) => setTimeout(resolve, 500))');

    $page->assertPathIs(unsavedChangesListPath())
        ->assertMissing('@unsaved-changes-dialog')
        ->assertNoJavaScriptErrors();
    expect($page->script('window.__backs'))->toBe(0)
        ->and($page->script('history.length === window.__entries'))->toBeTrue();
})->with(['save', 'close']);

test('after saving a post edited from the calendar, a channel drafts tab or the approvals tab, it lands back there and one back reaches the page before', function (string $from) {
    $post = seedUnsavedChangesReel();
    $account = $post->socialAccount;
    $state = match ($from) {
        'calendar' => ['status' => PostStatus::Scheduled, 'schedule_mode' => ScheduleMode::Custom, 'scheduled_at' => now()->addHour()],
        'approvals' => ['status' => PostStatus::PendingApproval, 'scheduled_at' => now()->addHour(), 'approval_requested_by' => $post->user_id],
        default => [],
    };
    $post->forceFill($state)->saveQuietly();
    $list = match ($from) {
        'calendar' => route('app.calendar', ['view' => 'week']),
        'channel drafts' => route('app.channels.publish', ['account' => $account, 'tab' => 'drafts']),
        'approvals' => route('app.posts.index', ['tab' => 'approvals']),
    };
    $listPath = (string) parse_url($list, PHP_URL_PATH);
    $before = (string) parse_url(route('app.settings.preferences'), PHP_URL_PATH);

    $page = visit(route('app.settings.preferences'))->resize(1280, 900)->navigate($list);
    if ($from === 'calendar') {
        waitForTestId($page, "calendar-post-{$post->id}");
        $page->click("@calendar-post-{$post->id}");
    }
    waitForTestId($page, "post-edit-{$post->id}");
    $page->click("@post-edit-{$post->id}");
    waitForTestId($page, 'instagram-share-to-feed');

    $page->click('@instagram-share-to-feed')
        ->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');
    $page->script('new Promise((resolve) => setTimeout(resolve, 500))');
    waitForPath($page, $listPath);
    waitForCondition($page, "!location.search.includes('edit=')");
    $page->assertPathIs($listPath)->assertMissing('@unsaved-changes-dialog');

    $page->back();
    waitForPath($page, $before);

    $page->assertPathIs($before)->assertNoJavaScriptErrors();
    expect(data_get($post->fresh()->meta, 'share_to_feed'))->toBeFalse();
})->with(['calendar', 'channel drafts', 'approvals']);

test('saving an edited post never moves through history before the saved page lands', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);
    $page->script(<<<'JS'
        (() => {
            window.__saved = false;
            window.__backsBeforeSaved = 0;
            const open = XMLHttpRequest.prototype.open;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                if (String(method).toUpperCase() === 'PUT') {
                    this.addEventListener('loadend', () => { window.__saved = true; });
                }
                return open.call(this, method, url, ...rest);
            };
            const back = window.history.back.bind(window.history);
            window.history.back = () => {
                if (!window.__saved) window.__backsBeforeSaved++;
                back();
            };
        })()
    JS);

    $page->click('@instagram-share-to-feed')
        ->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');
    $page->script('new Promise((resolve) => setTimeout(resolve, 500))');

    expect($page->script('window.__saved'))->toBeTrue()
        ->and($page->script('window.__backsBeforeSaved'))->toBe(0);
    expect(data_get($post->fresh()->meta, 'share_to_feed'))->toBeFalse();
});

test('closing an untouched post of any network asks nothing, even when its settings fill in on their own', function (Platform $platform, ContentType $contentType, array $meta, bool $clickTextFirst) {
    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => Http::response(['items' => [['id' => 'board_1', 'name' => 'Ideas']]]),
        '*' => Http::response([], 200),
    ]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'access_token' => 'channel-token',
        'token_expires_at' => now()->addDays(20),
    ]);
    $post = Post::factory()->forAccount($account, $contentType)->create([
        'user_id' => $user->id,
        'content' => 'Untouched post',
        'meta' => $meta,
    ]);
    $this->mock(DiscordClient::class, function (MockInterface $discord): void {
        $discord->shouldReceive('channels')->andReturn([['id' => '111', 'name' => 'general', 'type' => 0]]);
        $discord->shouldReceive('cachedChannels')->andReturnNull();
    });
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    if ($clickTextFirst) {
        waitForTestId($page, 'composer-editor-column');
        $page->script(<<<'JS'
            (() => {
                const target = document.querySelector('[data-testid="composer-editor-column"] [contenteditable="true"]') ?? document.querySelector('[data-testid="composer-editor-column"]');
                const options = { bubbles: true, cancelable: true, pointerType: 'mouse', button: 0 };
                target.dispatchEvent(new PointerEvent('pointerdown', options));
                target.dispatchEvent(new PointerEvent('pointerup', options));
                target.dispatchEvent(new MouseEvent('click', options));
            })()
        JS);
    }
    waitForTestId($page, 'channel-settings-rows');
    $page->script('new Promise((resolve) => setTimeout(resolve, 1000))');
    $page->click('@composer-close');
    waitForTestIdGoneOrShown($page, gone: 'post-composer-dialog', shown: 'unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
})->with([
    'youtube without a title' => [Platform::YouTube, ContentType::YouTubeShort, []],
    'pinterest with a board' => [Platform::Pinterest, ContentType::PinterestPin, ['board_id' => 'board_1']],
    'tiktok' => [Platform::TikTok, ContentType::TikTokVideo, []],
    'google business' => [Platform::GoogleBusiness, ContentType::GoogleBusinessPost, []],
    'discord without a channel name' => [Platform::Discord, ContentType::DiscordMessage, ['channel_id' => '111']],
])->with([
    'closed after the settings load' => [false],
    'text clicked right away' => [true],
]);

test('closing an untouched post with media, a time or a queue slot asks nothing', function (ContentType $contentType, array $media, array $schedule) {
    $post = seedUnsavedChangesReel();
    $post->forceFill(['content_type' => $contentType, 'media' => $media, ...$schedule])->save();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'channel-settings-rows');
    $page->script('new Promise((resolve) => setTimeout(resolve, 1000))');
    $page->click('@composer-close');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@unsaved-changes-dialog')->assertNoJavaScriptErrors();
})->with([
    'reel video, scheduled' => [ContentType::InstagramReel, [[
        'id' => 'video-1', 'type' => 'video', 'path' => 'medias/clip.mp4', 'url' => 'https://example.com/clip.mp4',
        'mime_type' => 'video/mp4', 'original_filename' => 'clip.mp4', 'meta' => ['cover_offset_ms' => 1200],
    ]], ['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDay()]],
    'carousel with tags, draft' => [ContentType::InstagramFeed, [
        ['id' => 'image-1', 'type' => 'image', 'path' => 'medias/a.jpg', 'url' => 'https://example.com/a.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'a.jpg', 'meta' => ['user_tags' => [['username' => 'alice', 'x' => 0.5, 'y' => 0.5]]]],
        ['id' => 'image-2', 'type' => 'image', 'path' => 'medias/b.jpg', 'url' => 'https://example.com/b.jpg', 'mime_type' => 'image/jpeg', 'original_filename' => 'b.jpg'],
    ], []],
]);

test('switching only the schedule of an edited post asks before closing', function () {
    $post = seedUnsavedChangesReel();
    $post->forceFill(['status' => PostStatus::Scheduled, 'scheduled_at' => now()->addDay()])->save();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'composer-schedule-trigger');
    $page->click('@composer-schedule-trigger');
    waitForTestId($page, 'composer-schedule-more-actions');
    $page->click('@composer-schedule-more-actions');
    waitForTestId($page, 'composer-schedule-now');
    $page->click('@composer-schedule-now');
    waitForTestIdState($page, 'composer-submit', "element.dataset.scheduleMode === 'now'");

    $page->click('@composer-close');
    waitForTestId($page, 'unsaved-changes-dialog');

    $page->assertVisible('@unsaved-changes-dialog')->assertNoJavaScriptErrors();
});

test('picking a discord channel stores its name for the preview', function () {
    Http::fake(['*' => Http::response([], 200)]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Discord]);
    $post = Post::factory()->forAccount($account, ContentType::DiscordMessage)->create([
        'user_id' => $user->id,
        'content' => 'Release notes',
        'meta' => [],
    ]);
    $this->mock(DiscordClient::class, function (MockInterface $discord): void {
        $discord->shouldReceive('channels')->andReturn([['id' => '111', 'name' => 'general', 'type' => 0]]);
        $discord->shouldReceive('cachedChannels')->andReturn([['id' => '111', 'name' => 'general']]);
    });
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForCondition($page, "document.querySelector('[data-testid=\"channel-settings-rows\"] [role=\"combobox\"]:not([disabled])')");
    $page->assertNoJavaScriptErrors()
        ->script("document.querySelector('[data-testid=\"channel-settings-rows\"] [role=\"combobox\"]').click()");
    waitForCondition($page, "[...document.querySelectorAll('[role=\"option\"]')].some((option) => option.textContent.includes('general'))");
    $page->script("[...document.querySelectorAll('[role=\"option\"]')].find((option) => option.textContent.includes('general')).click()");
    waitForCondition($page, "document.querySelector('[data-testid=\"channel-settings-rows\"] [role=\"combobox\"]')?.textContent.includes('general')");

    $page->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');

    expect($post->fresh()->meta)->toMatchArray(['channel_id' => '111', 'channel_name' => 'general']);
});

test('following an app link away from an edited post asks first and leaves only on discard', function () {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->click('@instagram-share-to-feed')
        ->script("document.querySelector('[data-testid=\"sidebar-logo\"]').click()");
    waitForTestId($page, 'unsaved-changes-dialog');

    $page->assertVisible('@post-composer-dialog')
        ->click('@unsaved-changes-keep');
    waitForTestIdGone($page, 'unsaved-changes-dialog');
    $page->assertVisible('@post-composer-dialog')
        ->script("document.querySelector('[data-testid=\"sidebar-logo\"]').click()");
    waitForTestId($page, 'unsaved-changes-discard');
    $page->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@post-composer-dialog')->assertNoJavaScriptErrors();
    expect($post->fresh()->meta)->toBe([]);
});

function unsavedChangesListPath(): string
{
    return (string) parse_url(route('app.posts.index'), PHP_URL_PATH);
}

function openUnsavedChangesFromList(Post $post, ?string $from = null, string $readyTestId = 'instagram-share-to-feed'): mixed
{
    $list = route('app.posts.index', ['tab' => 'drafts']);
    $page = $from === null
        ? visit($list)->resize(1280, 900)
        : visit($from)->resize(1280, 900)->navigate($list);
    waitForTestId($page, "post-edit-{$post->id}");
    $page->click("@post-edit-{$post->id}");
    waitForTestId($page, $readyTestId);

    return $page;
}

test('the browser back button on an edited post asks first, keeps the edit on cancel and goes back on discard', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);

    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $page->back();
    waitForTestId($page, 'unsaved-changes-dialog');

    $page->assertVisible('@post-composer-dialog')
        ->click('@unsaved-changes-keep');
    waitForTestIdGone($page, 'unsaved-changes-dialog');
    $page->assertVisible('@post-composer-dialog')
        ->assertAttribute('@instagram-share-to-feed', 'aria-checked', 'false');

    $page->back();
    waitForTestId($page, 'unsaved-changes-discard');
    $page->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@post-composer-dialog')
        ->assertPathIs(unsavedChangesListPath())
        ->assertNoJavaScriptErrors();
    expect($post->fresh()->meta)->toBe([]);
});

test('the browser back button on an untouched post closes it without asking', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);

    $page->back();
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs(unsavedChangesListPath())
        ->assertNoJavaScriptErrors();
    expect($page->script("location.search.includes('edit=')"))->toBeFalse();
});

test('after saving an edited post the browser back button does not reopen it or ask', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post, route('app.settings.preferences'));

    $page->click('@instagram-share-to-feed')
        ->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');
    waitForCondition($page, "!location.search.includes('edit=')");
    $page->back();
    $page->script('new Promise((resolve) => setTimeout(resolve, 800))');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs((string) parse_url(route('app.settings.preferences'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
    expect(data_get($post->fresh()->meta, 'share_to_feed'))->toBeFalse();
});

test('after discarding an edited post the browser back button does not reopen it or ask', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);

    $page->click('@instagram-share-to-feed')
        ->click('@composer-close');
    waitForTestId($page, 'unsaved-changes-discard');
    $page->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');
    $page->back();
    $page->script('new Promise((resolve) => setTimeout(resolve, 800))');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertNoJavaScriptErrors();
    expect($post->fresh()->meta)->toBe([]);
});

test('closing an untouched tiktok post asks nothing when the creator settings turn interactions off', function (Platform $platform, ContentType $contentType, array $meta, string $settledCondition) {
    Http::fake([
        config('trypost.platforms.tiktok.api').'/post/publish/creator_info/query/' => Http::response(['data' => [
            'creator_nickname' => 'Tik',
            'creator_username' => 'tiktoker',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
            'comment_disabled' => true,
            'duet_disabled' => true,
            'stitch_disabled' => true,
            'max_video_post_duration_sec' => 600,
        ]]),
        '*' => Http::response([], 200),
    ]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'access_token' => 'channel-token',
        'token_expires_at' => now()->addDays(20),
    ]);
    $post = Post::factory()->forAccount($account, $contentType)->create([
        'user_id' => $user->id,
        'content' => 'Untouched post',
        'meta' => $meta,
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'channel-settings-rows');
    waitForCondition($page, $settledCondition);
    $page->click('@composer-close');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
})->with([
    'tiktok creator turns interactions off' => [Platform::TikTok, ContentType::TikTokVideo, ['allow_comments' => true, 'allow_duet' => true, 'allow_stitch' => true], "document.querySelector('[data-testid=\"tiktok-allow-comments\"]')?.disabled === true"],
]);

test('a music cover renewed after the post opened does not count as an unsaved change', function () {
    $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    Http::fake([
        config('trypost.platforms.instagram-facebook.graph_api').'/846373660299207*' => Http::response([
            'audio_id' => '846373660299207', 'title' => 'Summer Song', 'cover_artwork_thumbnail_uri' => "{$pixel}#fresh",
        ]),
        '*' => Http::response([], 200),
    ]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::InstagramFacebook]);
    $post = Post::factory()->forAccount($account, ContentType::InstagramReel)->create([
        'user_id' => $user->id,
        'content' => 'Reel with music',
        'meta' => ['audio' => ['id' => '846373660299207', 'title' => 'Summer Song', 'cover_url' => $pixel, 'preview_url' => 'https://example.com/summer-song.m4a']],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-music-selected-cover');
    $page->script('new Promise((resolve) => setTimeout(resolve, 500))');
    $page->script("document.querySelector('[data-testid=\"instagram-music-selected-cover\"]').dispatchEvent(new Event('error'))");
    waitForTestIdState($page, 'instagram-music-selected-cover', "element.getAttribute('src')?.endsWith('#fresh')");
    $page->assertAttributeContains('@instagram-music-selected-cover', 'src', '#fresh')
        ->click('@composer-close');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

function seedUnsavedChangesReelWithBareTrack(): Post
{
    $pixel = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    Http::fake([
        config('trypost.platforms.instagram-facebook.graph_api').'/846373660299207*' => Http::response([
            'audio_id' => '846373660299207',
            'title' => 'Summer Song',
            'display_artist' => 'The Band',
            'cover_artwork_thumbnail_uri' => "{$pixel}#fresh",
            'download_url' => 'https://example.com/summer-song.m4a',
        ]),
        '*' => Http::response([], 200),
    ]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::InstagramFacebook]);
    $post = Post::factory()->forAccount($account, ContentType::InstagramReel)->create([
        'user_id' => $user->id,
        'content' => 'Reel with music',
        'meta' => ['audio' => ['id' => '846373660299207', 'audio_volume' => 40]],
    ]);
    test()->actingAs($user);

    return $post;
}

test('a reel whose track was saved with only its id shows the track name and cover', function () {
    $post = seedUnsavedChangesReelWithBareTrack();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestIdState($page, 'instagram-music-selected-cover', "element.getAttribute('src')?.endsWith('#fresh')");

    $page->assertAttributeContains('@instagram-music-selected-cover', 'src', '#fresh')
        ->assertSeeIn('@instagram-music-selected', 'Summer Song')
        ->assertSeeIn('@instagram-music-selected', 'The Band')
        ->assertSeeIn('@instagram-preview-audio', 'Summer Song · The Band')
        ->assertNoJavaScriptErrors();
});

test('track details that arrive after the first click do not count as an unsaved change', function () {
    $post = seedUnsavedChangesReelWithBareTrack();

    $page = visit(route('app.posts.index', ['tab' => 'drafts']))->resize(1280, 900);
    waitForTestId($page, "post-edit-{$post->id}");
    $page->script(<<<'JS'
        (() => {
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__url = String(url);
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (/\/instagram\/accounts\/[^/]+\/audio\/\d+/.test(this.__url)) {
                    setTimeout(() => send.call(this, body), 1500);
                    return;
                }
                return send.call(this, body);
            };
        })()
    JS);
    $page->click("@post-edit-{$post->id}");
    waitForTestId($page, 'composer-editor-column');
    $page->script(<<<'JS'
        (() => {
            const target = document.querySelector('[data-testid="composer-editor-column"] [contenteditable="true"]') ?? document.querySelector('[data-testid="composer-editor-column"]');
            const options = { bubbles: true, cancelable: true, pointerType: 'mouse', button: 0 };
            target.dispatchEvent(new PointerEvent('pointerdown', options));
            target.dispatchEvent(new PointerEvent('pointerup', options));
        })()
    JS);
    $page->assertMissing('@instagram-music-selected-cover');
    waitForTestIdState($page, 'instagram-music-selected-cover', "element.getAttribute('src')?.endsWith('#fresh')");
    $page->assertAttributeContains('@instagram-music-selected-cover', 'src', '#fresh')
        ->click('@composer-close');
    waitForTestIdGoneOrShown($page, gone: 'post-composer-dialog', shown: 'unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('after leaving an edited post through an app link the browser back button returns to the post once', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);

    $page->click('@instagram-share-to-feed')
        ->script("document.querySelector('[data-testid=\"sidebar-logo\"]').click()");
    waitForTestId($page, 'unsaved-changes-discard');
    $page->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->back();
    waitForTestId($page, 'instagram-share-to-feed');
    $page->assertAttribute('@instagram-share-to-feed', 'aria-checked', 'true');

    $page->back();
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs(unsavedChangesListPath())
        ->assertNoJavaScriptErrors();
});

test('after keeping an edited post on back and then leaving through an app link the browser back button returns to the post once', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);

    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $page->back();
    waitForTestId($page, 'unsaved-changes-keep');
    $page->click('@unsaved-changes-keep');
    waitForTestIdGone($page, 'unsaved-changes-dialog');
    $page->script("document.querySelector('[data-testid=\"sidebar-logo\"]').click()");
    waitForTestId($page, 'unsaved-changes-discard');
    $page->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->back();
    waitForTestId($page, 'instagram-share-to-feed');
    $page->assertVisible('@post-composer-dialog')
        ->assertAttribute('@instagram-share-to-feed', 'aria-checked', 'true');

    $page->back();
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs(unsavedChangesListPath())
        ->assertNoJavaScriptErrors();
});

test('saving an edited post after keeping it on back leaves the list page restorable from history', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);
    $listPath = unsavedChangesListPath();

    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $page->back();
    waitForTestId($page, 'unsaved-changes-keep');
    $page->click('@unsaved-changes-keep');
    waitForTestIdGone($page, 'unsaved-changes-dialog');
    $page->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');
    $page->script("document.querySelector('[data-testid=\"sidebar-logo\"]').click()");
    waitForCondition($page, "location.pathname !== '{$listPath}'");

    $page->back();
    waitForTestId($page, "post-edit-{$post->id}");

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs($listPath);
    expect($page->script('window.history.state !== null'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('pressing escape on an edited post asks first', function () {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->click('@instagram-share-to-feed')
        ->keys('@instagram-share-to-feed', 'Escape');
    waitForTestId($page, 'unsaved-changes-dialog');

    $page->assertVisible('@unsaved-changes-dialog')
        ->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('closing a new post with text never asks, since it is kept as an autosaved draft', function () {
    $post = seedUnsavedChangesReel();
    $account = $post->socialAccount;

    $page = visit(route('app.posts.index'))->resize(1280, 900);
    waitForTestId($page, 'posts-new-post');
    $page->click('@posts-new-post');
    waitForTestId($page, 'composer-add-account');
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$account->id}")
        ->fill("@composer-caption-{$account->id}", 'A new idea')
        ->click('@composer-close');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('a discord channel missing from the list shows its stored name and keeps only its id on save', function () {
    Http::fake(['*' => Http::response([], 200)]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::Discord]);
    $post = Post::factory()->forAccount($account, ContentType::DiscordMessage)->create([
        'user_id' => $user->id,
        'content' => 'Release notes',
        'meta' => ['channel_id' => '999', 'channel_name' => 'announcements'],
    ]);
    $this->mock(DiscordClient::class, function (MockInterface $discord): void {
        $discord->shouldReceive('channels')->andReturn([['id' => '111', 'name' => 'general', 'type' => 0]]);
        $discord->shouldReceive('cachedChannels')->andReturnNull();
    });
    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForCondition($page, "document.querySelector('[data-testid=\"channel-settings-rows\"] [role=\"combobox\"]:not([disabled])')?.textContent.includes('announcements')");

    expect($page->script("document.querySelector('[data-testid=\"channel-settings-rows\"] [role=\"combobox\"]').textContent.trim()"))->toBe('#announcements');
    $page->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertNoJavaScriptErrors();
    expect($post->fresh()->meta)->toHaveKey('channel_id', '999')
        ->not->toHaveKey('channel_name');
});

test('pressing back again while the unsaved changes question is open keeps the edit and still asks', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);

    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $page->back();
    waitForTestId($page, 'unsaved-changes-dialog');
    $page->back();
    $page->script('new Promise((resolve) => setTimeout(resolve, 500))');

    $page->assertVisible('@unsaved-changes-dialog')
        ->click('@unsaved-changes-keep');
    waitForTestIdGone($page, 'unsaved-changes-dialog');

    $page->assertVisible('@post-composer-dialog')
        ->assertAttribute('@instagram-share-to-feed', 'aria-checked', 'false')
        ->assertNoJavaScriptErrors();
});

test('discarding after back closes a post opened straight from a link, with no page to go back to', function () {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $page->back();
    waitForTestId($page, 'unsaved-changes-discard');
    $page->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertVisible('@sidebar-logo')
        ->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs(unsavedChangesListPath())
        ->assertNoJavaScriptErrors();
    expect($post->fresh()->meta)->toBe([]);
});

test('the command palette shortcut and the workspace switcher cannot leave an edited post without asking', function () {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->click('@instagram-share-to-feed');
    $page->script("window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true, bubbles: true, cancelable: true }))");
    $page->assertMissing('@command-palette')
        ->assertVisible('@post-composer-dialog');

    $switcherCovered = $page->script(<<<'JS'
        (() => {
            const menu = document.querySelector('[data-testid="sidebar-workspace-menu"]');
            const box = menu.getBoundingClientRect();

            return !menu.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2));
        })()
    JS);

    expect($switcherCovered)->toBeTrue();
    $page->assertMissing('@sidebar-workspaces-trigger')
        ->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('clicking outside an edited post asks first', function () {
    $post = seedUnsavedChangesReel();

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitForTestId($page, 'instagram-share-to-feed');
    $page->click('@instagram-share-to-feed');
    $page->script(<<<'JS'
        (() => {
            const outside = document.elementFromPoint(5, 5);
            const options = { bubbles: true, cancelable: true, clientX: 5, clientY: 5, pointerType: 'mouse', button: 0 };
            outside.dispatchEvent(new PointerEvent('pointerdown', options));
            outside.dispatchEvent(new PointerEvent('pointerup', options));
            outside.dispatchEvent(new MouseEvent('click', options));
        })()
    JS);
    waitForTestId($page, 'unsaved-changes-dialog');

    $page->assertVisible('@unsaved-changes-dialog')
        ->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('the list behind an edited post keeps what a reload brought in after backs, leaving and coming back', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);

    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $arrived = Post::factory()->forAccount($post->socialAccount, ContentType::InstagramReel)->create([
        'user_id' => $post->user_id,
        'content' => 'Arrived while editing',
        'meta' => [],
    ]);
    $page->script("document.getElementById('app').__vue_app__.config.globalProperties.\$inertia.reload({ only: ['queue', 'posts', 'counts', 'hasData', 'channels'], reset: ['posts'] })");
    waitForTestId($page, "post-edit-{$arrived->id}");

    foreach (range(1, 2) as $attempt) {
        $page->back();
        waitForTestId($page, 'unsaved-changes-keep');
        $page->click('@unsaved-changes-keep');
        waitForTestIdGone($page, 'unsaved-changes-dialog');
    }
    $page->script("document.querySelector('[data-testid=\"sidebar-logo\"]').click()");
    waitForTestId($page, 'unsaved-changes-discard');
    $page->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->back();
    waitForTestId($page, 'instagram-share-to-feed');

    expect($page->script("Boolean(document.querySelector('[data-testid=\"post-edit-{$arrived->id}\"]'))"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('jumping further back in history from an edited post leaves without asking or rewriting history', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post, route('app.settings.preferences'));

    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $length = $page->script('window.history.length');
    $page->script('window.history.go(-2)');
    waitForTestIdGone($page, 'post-composer-dialog');
    $page->script('new Promise((resolve) => setTimeout(resolve, 800))');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs(unsavedChangesListPath());
    expect($page->script('window.history.length'))->toBe($length)
        ->and($page->script('window.history.state !== null'))->toBeTrue();

    $page->back();
    waitForPath($page, parse_url(route('app.settings.preferences'), PHP_URL_PATH));

    $page->assertPathIs((string) parse_url(route('app.settings.preferences'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
});

test('a save that fails validation keeps the edited post open and still asks before closing, then saves once fixed', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post);

    $page->type('@instagram-collaborator-input', 'bad-name!')
        ->click('@instagram-collaborator-add');
    waitForTestId($page, 'instagram-collaborators-notice');
    $page->click('@instagram-collaborators-notice-dismiss');
    waitForTestIdGone($page, 'instagram-collaborators-notice');
    $page->click('@composer-save-draft');
    waitForCondition($page, 'document.body.textContent.includes('.json_encode(__('posts.form.instagram.collaborators.invalid')).')');

    $page->assertSee(__('posts.form.instagram.collaborators.invalid'))
        ->assertVisible('@post-composer-dialog')
        ->click('@composer-close');
    waitForTestId($page, 'unsaved-changes-keep');
    $page->click('@unsaved-changes-keep');
    waitForTestIdGone($page, 'unsaved-changes-dialog');

    $page->click('@instagram-collaborator-remove-bad-name!')
        ->type('@instagram-collaborator-input', 'alice')
        ->click('@instagram-collaborator-add');
    waitForTestId($page, 'instagram-collaborator-alice');
    $page->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');
    $page->back();
    $page->script('new Promise((resolve) => setTimeout(resolve, 800))');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertNoJavaScriptErrors();
    expect(data_get($post->fresh()->meta, 'collaborators'))->toBe(['alice']);
});

test('undoing the edit after keeping it on back closes without asking and back leaves the post', function () {
    $post = seedUnsavedChangesReel();
    $post->update(['meta' => ['share_to_feed' => true]]);
    $page = openUnsavedChangesFromList($post, route('app.settings.preferences'));

    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $page->back();
    waitForTestId($page, 'unsaved-changes-keep');
    $page->click('@unsaved-changes-keep');
    waitForTestIdGone($page, 'unsaved-changes-dialog');
    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'true'");
    $page->script('new Promise((resolve) => setTimeout(resolve, 500))');

    $page->click('@composer-close');
    waitForTestIdGone($page, 'post-composer-dialog');
    waitForCondition($page, "!location.search.includes('edit=')");
    $page->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs(unsavedChangesListPath());

    $page->back();
    waitForPath($page, parse_url(route('app.settings.preferences'), PHP_URL_PATH));

    $page->assertPathIs((string) parse_url(route('app.settings.preferences'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
    expect($post->fresh()->meta)->toBe(['share_to_feed' => true]);
});

test('saving an edited post that was published meanwhile closes it and back does not reopen it', function () {
    $post = seedUnsavedChangesReel();
    $page = openUnsavedChangesFromList($post, route('app.settings.preferences'));

    $page->click('@instagram-share-to-feed');
    waitForTestIdState($page, 'instagram-share-to-feed', "element.getAttribute('aria-checked') === 'false'");
    $post->forceFill(['status' => PostStatus::Published, 'published_at' => now()])->save();
    $page->click('@composer-save-draft');
    waitForTestIdGone($page, 'post-composer-dialog');
    waitForCondition($page, "!location.search.includes('edit=')");
    $page->back();
    $page->script('new Promise((resolve) => setTimeout(resolve, 800))');

    $page->assertMissing('@post-composer-dialog')
        ->assertMissing('@unsaved-changes-dialog')
        ->assertPathIs((string) parse_url(route('app.settings.preferences'), PHP_URL_PATH))
        ->assertNoJavaScriptErrors();
    expect($post->fresh()->meta)->toBe([]);
});

test('a tiktok post clicked before its creator settings load asks nothing on close', function () {
    Http::fake([
        config('trypost.platforms.tiktok.api').'/post/publish/creator_info/query/' => Http::response(['data' => [
            'creator_nickname' => 'Tik',
            'creator_username' => 'tiktoker',
            'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'SELF_ONLY'],
            'comment_disabled' => true,
            'duet_disabled' => true,
            'stitch_disabled' => true,
            'max_video_post_duration_sec' => 600,
        ]]),
        '*' => Http::response([], 200),
    ]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::TikTok,
        'access_token' => 'channel-token',
        'token_expires_at' => now()->addDays(20),
    ]);
    $post = Post::factory()->forAccount($account, ContentType::TikTokVideo)->create([
        'user_id' => $user->id,
        'content' => 'Untouched post',
        'meta' => ['allow_comments' => true, 'allow_duet' => true, 'allow_stitch' => true],
    ]);
    $this->actingAs($user);

    $page = visit(route('app.posts.index', ['tab' => 'drafts']))->resize(1280, 900);
    waitForTestId($page, "post-edit-{$post->id}");
    $page->script(<<<'JS'
        (() => {
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__url = String(url);
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (this.__url.includes('/posts/composer/accounts/')) {
                    setTimeout(() => send.call(this, body), 1500);
                    return;
                }
                return send.call(this, body);
            };
        })()
    JS);
    $page->click("@post-edit-{$post->id}");
    waitForTestId($page, 'composer-editor-column');
    $page->script(<<<'JS'
        (() => {
            const target = document.querySelector('[data-testid="composer-editor-column"] [contenteditable="true"]') ?? document.querySelector('[data-testid="composer-editor-column"]');
            const options = { bubbles: true, cancelable: true, pointerType: 'mouse', button: 0 };
            target.dispatchEvent(new PointerEvent('pointerdown', options));
            target.dispatchEvent(new PointerEvent('pointerup', options));
        })()
    JS);
    expect($page->script("document.querySelector('[data-testid=\"tiktok-allow-comments\"]')?.disabled === true"))->toBeFalse();
    waitForTestIdState($page, 'tiktok-allow-comments', 'element.disabled === true');
    $page->click('@composer-close');
    waitForTestIdGoneOrShown($page, gone: 'post-composer-dialog', shown: 'unsaved-changes-dialog');

    $page->assertMissing('@unsaved-changes-dialog')
        ->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('closing an edited post while a file is still uploading asks first', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->forAccount($account, ContentType::LinkedInPost)->create([
        'user_id' => $user->id,
        'content' => 'Uploading',
        'meta' => [],
    ]);
    $this->actingAs($user);

    $page = openUnsavedChangesFromList($post, null, "composer-{$account->id}-dropzone");
    $page->script(<<<'JS'
        (() => {
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__url = String(url);
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (this.__url.includes('/media/chunked')) {
                    return;
                }
                return send.call(this, body);
            };
        })()
    JS);
    $png = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (() => {
            const bytes = Uint8Array.from(atob('{$png}'), (character) => character.charCodeAt(0));
            const input = document.querySelector('[data-testid="composer-{$account->id}-file-input"]');
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'uploading.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })()
    JS);
    waitForTestId($page, "composer-{$account->id}-upload-item");
    $page->click('@composer-close');
    waitForTestId($page, 'unsaved-changes-dialog');

    $page->assertVisible('@unsaved-changes-dialog')
        ->click('@unsaved-changes-discard');
    waitForTestIdGone($page, 'post-composer-dialog');

    $page->assertMissing('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});

test('manage posting times in the schedule menu of an edited post asks first instead of the browser prompt', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->create(['workspace_id' => $workspace->id, 'platform' => Platform::LinkedIn]);
    $post = Post::factory()->forAccount($account, ContentType::LinkedInPost)->create([
        'user_id' => $user->id,
        'content' => 'Queue me',
        'meta' => [],
    ]);
    $this->actingAs($user);

    $page = openUnsavedChangesFromList($post, null, "composer-caption-{$account->id}");
    $page->click("@composer-caption-{$account->id}")
        ->type("@composer-caption-{$account->id}", ' later')
        ->click('@composer-schedule-trigger');
    waitForTestId($page, 'composer-queue-manage-slots');
    $page->click('@composer-queue-manage-slots');
    waitForTestId($page, 'unsaved-changes-dialog');

    $page->assertVisible('@unsaved-changes-dialog')
        ->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
});
