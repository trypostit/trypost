<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Enums\YouTube\Category;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\ContentSanitizer;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<int, array<string, mixed>>  $media
 */
function seedChannelSettingsPost(Platform $platform, ContentType $contentType, array $media = []): PostPlatform
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'username' => 'trypostit',
        'access_token' => 'channel-token',
        'token_expires_at' => now()->addDays(20),
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Channel settings',
        'media' => $media,
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $platform,
        'content_type' => $contentType,
        'meta' => [],
    ]);

    test()->actingAs($user);

    return $postPlatform;
}

function waitForChannelSettingsCondition(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 300; i++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForChannelSettingsTestId(mixed $page, string $testId): void
{
    waitForChannelSettingsCondition(
        $page,
        "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0",
    );
}

function fakeChannelSettingsApis(): void
{
    $boards = [
        ['id' => 'board_1', 'name' => 'Disney', 'media' => ['image_cover_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==']],
        ['id' => 'board_2', 'name' => 'Social'],
    ];
    $listCalls = 0;

    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => function (Request $request) use (&$listCalls, $boards) {
            if ($request->method() === 'POST') {
                return Http::response(['id' => 'board_new', 'name' => data_get($request->data(), 'name')], 201);
            }

            $listCalls++;

            return Http::response([
                'items' => $listCalls === 1 ? $boards : [...$boards, ['id' => 'board_3', 'name' => 'Universal']],
            ]);
        },
        '*' => Http::response([], 200),
    ]);
}

test('every network with settings renders them as label and control rows under the editor', function (Platform $platform, ContentType $contentType, array $media) {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost($platform, $contentType, $media);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'channel-settings-rows');

    $layout = $page->script(<<<'JS'
        (() => {
            const card = document.querySelector('[data-testid=composer-customization]');
            const section = card.querySelector('[data-testid=channel-settings-rows]');
            const row = section.querySelector('[data-testid=channel-settings-row]');
            const label = row.firstElementChild;
            const caption = card.querySelector('textarea');
            return {
                insideCard: card.contains(section),
                belowEditor: !!(caption.compareDocumentPosition(section) & Node.DOCUMENT_POSITION_FOLLOWING),
                columns: getComputedStyle(row).gridTemplateColumns.split(' ')[0],
                labelSize: getComputedStyle(label).fontSize,
                labelWeight: getComputedStyle(label).fontWeight,
                divider: getComputedStyle(section).borderTopWidth,
                noOverflow: document.documentElement.scrollWidth <= window.innerWidth,
                inDispatcher: !!card.querySelector('[data-testid=composer-network-settings] [data-testid=channel-settings-rows]'),
            };
        })();
    JS);

    expect($layout)->toEqual([
        'insideCard' => true,
        'belowEditor' => true,
        'columns' => '130px',
        'labelSize' => '13px',
        'labelWeight' => '500',
        'divider' => '1px',
        'noOverflow' => true,
        'inDispatcher' => true,
    ]);
    $page->assertMissing('@facebook-settings-toggle')
        ->assertMissing('@tiktok-settings-toggle')
        ->assertNoJavaScriptErrors();
})->with([
    'facebook' => [Platform::Facebook, ContentType::FacebookPost, []],
    'tiktok' => [Platform::TikTok, ContentType::TikTokVideo, []],
    'pinterest' => [Platform::Pinterest, ContentType::PinterestPin, []],
    'youtube' => [Platform::YouTube, ContentType::YouTubeShort, []],
    'google business' => [Platform::GoogleBusiness, ContentType::GoogleBusinessPost, []],
    'discord' => [Platform::Discord, ContentType::DiscordMessage, []],
    'linkedin document' => [Platform::LinkedIn, ContentType::LinkedInPost, [[
        'id' => 'd1',
        'type' => 'document',
        'mime_type' => 'application/pdf',
        'path' => 'uploads/deck.pdf',
        'url' => 'https://cdn.test/deck.pdf',
        'size' => 1024,
        'original_filename' => 'deck.pdf',
    ]]],
]);

test('the instagram channel card picks its content type from a radio row above the editor', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Instagram, ContentType::InstagramFeed);
    $id = $postPlatform->social_account_id;

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-type-{$id}-instagram_feed");

    $page->assertAttribute("@composer-type-{$id}-instagram_feed", 'aria-checked', 'true')
        ->assertAttribute("@composer-type-{$id}-instagram_reel", 'aria-checked', 'false')
        ->assertPresent("@composer-type-{$id}-instagram_story")
        ->assertMissing('@instagram-settings-toggle');

    expect($page->script(<<<JS
        (() => {
            const radios = document.querySelector('[data-testid="composer-type-{$id}"]');
            const caption = document.querySelector('[data-testid="composer-caption-{$id}"]');
            return !!(radios.compareDocumentPosition(caption) & Node.DOCUMENT_POSITION_FOLLOWING);
        })();
    JS))->toBeTrue();

    $page->click("@composer-type-{$id}-instagram_reel");
    waitForChannelSettingsCondition($page, "document.querySelector('[data-testid=\"composer-type-{$id}-instagram_reel\"]')?.getAttribute('aria-checked') === 'true'");
    $page->assertAttribute("@composer-type-{$id}-instagram_reel", 'aria-checked', 'true')
        ->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');
    expect($postPlatform->fresh()->content_type)->toBe(ContentType::InstagramReel);
});

test('a pinterest pin takes its type from the media', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Pinterest, ContentType::PinterestPin);
    $id = $postPlatform->social_account_id;
    $images = Media::factory()->count(2)->temporaryUpload($postPlatform->post->workspace)->create(['mime_type' => 'image/png']);
    $postPlatform->post->update(['media' => $images->map(fn (Media $media): array => MediaItem::fromMedia($media)->toArray())->all()]);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'pinterest-board-trigger');

    $page->assertMissing("@composer-type-{$id}")
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft')
        ->assertMissing('@post-composer-dialog');

    expect($postPlatform->fresh()->content_type)->toBe(ContentType::PinterestCarousel);
});

test('the pinterest board picker searches, refreshes and creates boards', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Pinterest, ContentType::PinterestPin);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'pinterest-board-trigger');

    $page->click('@pinterest-board-trigger');
    waitForChannelSettingsTestId($page, 'pinterest-board-option-board_1');
    $page->assertSeeIn('@pinterest-board-picker', '@trypostit')
        ->assertVisible('@pinterest-board-option-board_2');
    expect($page->script("!!document.querySelector('[data-testid=\"pinterest-board-option-board_1\"] img')"))->toBeTrue();

    $page->fill('@pinterest-board-search', 'dis');
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"pinterest-board-option-board_2\"]')");
    $page->assertMissing('@pinterest-board-option-board_2')
        ->assertVisible('@pinterest-board-option-board_1')
        ->fill('@pinterest-board-search', '');

    $page->click('@pinterest-boards-refresh');
    waitForChannelSettingsTestId($page, 'pinterest-board-option-board_3');
    $page->assertSeeIn('@pinterest-board-option-board_3', 'Universal');

    $page->fill('@pinterest-board-new-name', 'Recipes')
        ->click('@pinterest-board-create');
    waitForChannelSettingsTestId($page, 'pinterest-board-selected');
    $page->assertSeeIn('@pinterest-board-selected', 'Recipes')
        ->assertMissing('@pinterest-board-picker')
        ->assertNoJavaScriptErrors();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === config('trypost.platforms.pinterest.api').'/boards'
        && $request->data() === ['name' => 'Recipes']);

    $page->click('@pinterest-board-trigger');
    waitForChannelSettingsTestId($page, 'pinterest-board-new-name');
    expect($page->script("document.querySelector('[data-testid=\"pinterest-board-new-name\"]').value"))->toBe('');

    $page->click('@pinterest-boards-refresh');
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"pinterest-board-option-board_new\"]')");
    $page->assertMissing('@pinterest-board-option-board_new')
        ->assertVisible('@pinterest-board-option-board_3');
    $page->click('@pinterest-board-option-board_3');
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"pinterest-board-picker\"]')");

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');
    expect(data_get($postPlatform->fresh()->meta, 'board_id'))->toBe('board_3');
});

test('the pinterest board picker shows the reconnect error when pinterest refuses the board', function () {
    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['code' => 3, 'message' => 'Not authorized'], 403)
            : Http::response(['items' => [['id' => 'board_1', 'name' => 'Disney']]]),
        '*' => Http::response([], 200),
    ]);
    $postPlatform = seedChannelSettingsPost(Platform::Pinterest, ContentType::PinterestPin);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'pinterest-board-trigger');
    $page->click('@pinterest-board-trigger');
    waitForChannelSettingsTestId($page, 'pinterest-board-new-name');

    $page->fill('@pinterest-board-new-name', 'Recipes')
        ->click('@pinterest-board-create');
    waitForChannelSettingsTestId($page, 'pinterest-board-create-error');

    $page->assertSeeIn('@pinterest-board-create-error', __('posts.form.pinterest.boards_reconnect'))
        ->assertMissing('@pinterest-board-selected')
        ->assertNoJavaScriptErrors();
});

test('the google business card picks its type in the header and lists the fields in the reference order', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::GoogleBusiness, ContentType::GoogleBusinessPost);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'google-business-topic-STANDARD');
    $fields = <<<'JS'
        [...document.querySelectorAll('[data-testid="composer-customization"] [data-testid^="google-business-"]')]
            .map((node) => node.dataset.testid)
            .filter((testId) => ['google-business-title', 'google-business-add-time', 'google-business-start', 'google-business-end',
                'google-business-add-details', 'google-business-coupon', 'google-business-redeem', 'google-business-terms',
                'google-business-cta', 'google-business-cta-url'].includes(testId))
    JS;

    expect($page->script("!!document.querySelector('[data-testid=\"composer-customization\"] [data-testid$=\"-header\"] [data-testid=\"google-business-topic\"]')"))->toBeTrue()
        ->and($page->script("!!document.querySelector('[data-testid=\"composer-customization\"] [data-testid$=\"-header\"] [data-testid=\"google-business-topic-help\"]')"))->toBeTrue()
        ->and($page->script($fields))->toBe(['google-business-cta']);

    $page->click('@google-business-topic-OFFER');
    waitForChannelSettingsTestId($page, 'google-business-add-details');
    expect($page->script($fields))->toBe(['google-business-title', 'google-business-start', 'google-business-end', 'google-business-add-details'])
        ->and($page->script("document.querySelector('[data-testid=\"google-business-add-details\"]').getAttribute('aria-checked')"))->toBe('false');

    $page->click('@google-business-topic-STANDARD');
    waitForChannelSettingsTestId($page, 'google-business-cta');
    $page->click('@google-business-cta');
    waitForChannelSettingsTestId($page, 'google-business-cta-option-BOOK');
    $page->click('@google-business-cta-option-BOOK');
    waitForChannelSettingsTestId($page, 'google-business-cta-url');
    $page->fill('@google-business-cta-url', 'https://trypost.it/book')
        ->click('@google-business-topic-OFFER');
    waitForChannelSettingsTestId($page, 'google-business-redeem');

    expect($page->script($fields))->toBe(['google-business-title', 'google-business-start', 'google-business-end',
        'google-business-add-details', 'google-business-coupon', 'google-business-redeem', 'google-business-terms']);
    $page->assertValue('@google-business-redeem', 'https://trypost.it/book')
        ->click('@google-business-add-details');
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"google-business-coupon\"]')");
    $page->assertMissing('@google-business-redeem');

    $page->click('@google-business-topic-EVENT');
    waitForChannelSettingsTestId($page, 'google-business-add-time');
    expect($page->script($fields))->toBe(['google-business-title', 'google-business-add-time', 'google-business-start',
        'google-business-end', 'google-business-cta']);

    $page->fill('@google-business-title', 'Launch night')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft')
        ->assertMissing('@post-composer-dialog');

    $meta = $postPlatform->fresh()->meta;
    expect(data_get($meta, 'topic_type'))->toBe('EVENT')
        ->and(data_get($meta, 'event.title'))->toBe('Launch night')
        ->and(data_get($meta, 'event.start_date'))->not->toBeNull()
        ->and(data_get($meta, 'event.end_date'))->toBe(Carbon::parse(data_get($meta, 'event.start_date'))->addDays(7)->format('Y-m-d'))
        ->and(data_get($meta, 'event.start_time'))->toBeNull()
        ->and(data_get($meta, 'offer'))->toBeNull()
        ->and(data_get($meta, 'call_to_action'))->toBeNull();
});

test('the google business panel shows a server validation error under its field', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::GoogleBusiness, ContentType::GoogleBusinessPost);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'google-business-cta');
    $page->click('@google-business-cta');
    waitForChannelSettingsTestId($page, 'google-business-cta-option-BOOK');
    $page->click('@google-business-cta-option-BOOK');
    waitForChannelSettingsTestId($page, 'google-business-cta-url');
    $page->fill('@google-business-cta-url', 'not a link')
        ->click('@composer-save-draft');
    waitForChannelSettingsCondition($page, "document.querySelector('[data-testid=\"google-business-cta-url\"]')?.getAttribute('aria-invalid') === 'true'");

    expect($page->script("document.querySelector('[data-testid=\"google-business-cta-url\"]').getAttribute('aria-invalid')"))->toBe('true')
        ->and($page->script("document.querySelector('[data-testid=\"google-business-cta-url\"]').closest('[data-testid=\"channel-settings-row\"]').textContent"))
        ->toContain(__('validation.url', ['attribute' => __('posts.form.google_business.cta_url')]));
    $page->assertVisible('@post-composer-dialog')
        ->assertNoJavaScriptErrors();
    expect(data_get($postPlatform->fresh()->meta, 'call_to_action'))->toBeNull();
});

test('the google business event shows start and end time fields beside the dates when add time is on', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::GoogleBusiness, ContentType::GoogleBusinessPost);
    $postPlatform->update(['meta' => ['topic_type' => 'EVENT', 'event' => ['title' => 'Launch night', 'start_date' => '2037-03-10', 'end_date' => '2037-03-12']]]);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'google-business-add-time');
    $page->assertMissing('@google-business-start-time')
        ->assertMissing('@google-business-end-time')
        ->click('@google-business-add-time');
    waitForChannelSettingsTestId($page, 'google-business-start-time');

    $layout = $page->script(<<<'JS'
        (() => {
            const box = (id) => document.querySelector(`[data-testid="${id}"]`).getBoundingClientRect();
            const [start, startTime, end, endTime] = ['google-business-start', 'google-business-start-time', 'google-business-end', 'google-business-end-time'].map(box);
            return {
                startRow: Math.abs(start.top + start.height / 2 - (startTime.top + startTime.height / 2)) < 4 && startTime.left > start.right,
                endRow: Math.abs(end.top + end.height / 2 - (endTime.top + endTime.height / 2)) < 4 && endTime.left > end.right,
                empty: [document.querySelector('[data-testid="google-business-start-time"]').value, document.querySelector('[data-testid="google-business-end-time"]').value],
            };
        })();
    JS);
    expect($layout)->toEqual(['startRow' => true, 'endRow' => true, 'empty' => ['', '']]);

    $page->fill('@google-business-start-time', '18:30')
        ->fill('@google-business-end-time', '21:00')
        ->assertVisible('@google-business-event-timezone-hint')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft')
        ->assertMissing('@post-composer-dialog');

    expect(data_get($postPlatform->fresh()->meta, 'event'))->toEqual([
        'title' => 'Launch night',
        'start_date' => '2037-03-10',
        'end_date' => '2037-03-12',
        'start_time' => '18:30',
        'end_time' => '21:00',
    ]);
});

test('the youtube card saves its fields in the reference order with the AI label last', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::YouTube, ContentType::YouTubeShort);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'youtube-title');

    $order = ['youtube-title', 'youtube-category', 'youtube-privacy', 'youtube-license',
        'youtube-notify-subscribers', 'youtube-embeddable', 'youtube-made-for-kids', 'youtube-ai-generated'];
    $orderJson = json_encode($order);
    expect($page->script(<<<JS
        [...document.querySelectorAll('[data-testid="composer-customization"] [data-testid^="youtube-"]')]
            .map((node) => node.dataset.testid)
            .filter((testId) => {$orderJson}.includes(testId))
    JS))->toBe($order)
        ->and($page->script(<<<'JS'
            (() => {
                const sections = document.querySelectorAll('[data-testid="composer-customization"] [data-testid="channel-settings-rows"]');
                return !!sections[sections.length - 1].querySelector('[data-testid="youtube-ai-generated"]');
            })()
        JS))->toBeTrue();

    $page->assertMissing('@youtube-description-0')
        ->assertValue('@youtube-title', 'Channel settings')
        ->fill('@youtube-title', 'Explicit title')
        ->click('@youtube-category');
    waitForChannelSettingsTestId($page, 'youtube-category-27');
    $page->click('@youtube-category-27')->click('@youtube-privacy');
    waitForChannelSettingsTestId($page, 'youtube-privacy-unlisted');
    $page->click('@youtube-privacy-unlisted')->click('@youtube-license');
    waitForChannelSettingsTestId($page, 'youtube-license-creativeCommon');
    $page->click('@youtube-license-creativeCommon')
        ->click('@youtube-notify-subscribers')
        ->click('@youtube-made-for-kids')
        ->click('@youtube-ai-generated')
        ->assertDontSeeIn('@youtube-preview', 'Explicit title')
        ->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');

    expect($postPlatform->fresh()->meta)->toEqual([
        'title' => 'Explicit title',
        'category_id' => '27',
        'privacy_status' => 'unlisted',
        'license' => 'creativeCommon',
        'notify_subscribers' => false,
        'made_for_kids' => true,
        'is_ai_generated' => true,
    ]);
});

test('the youtube category lists the 15 backend categories in order with people and blogs by default', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::YouTube, ContentType::YouTubeShort);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'youtube-category');

    $page->assertSeeIn('@youtube-category', __(Category::DEFAULT->labelKey()))
        ->click('@youtube-category');
    waitForChannelSettingsTestId($page, 'youtube-category-'.Category::TravelAndEvents->value);

    expect($page->script(<<<'JS'
        [...document.querySelectorAll('[data-testid^="youtube-category-"]')]
            .map((node) => [node.dataset.testid.replace('youtube-category-', ''), node.textContent.trim()])
    JS))->toBe(array_map(fn (Category $category): array => [$category->value, __($category->labelKey())], Category::cases()));
    $page->assertNoJavaScriptErrors();
});

test('the tiktok AI label is the last row and saves is_aigc', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::TikTok, ContentType::TikTokVideo);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'tiktok-ai-generated');

    expect($page->script(<<<'JS'
        (() => {
            const sections = document.querySelectorAll('[data-testid="composer-customization"] [data-testid="channel-settings-rows"]');
            return !!sections[sections.length - 1].querySelector('[data-testid="tiktok-ai-generated"]');
        })()
    JS))->toBeTrue();

    $page->click('@tiktok-ai-generated')->assertNoJavaScriptErrors();
    expect($page->script("document.querySelector('[data-testid=\"tiktok-ai-generated\"]').getAttribute('aria-checked')"))->toBe('true');

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');

    expect(data_get($postPlatform->fresh()->meta, 'is_aigc'))->toBeTrue();
});

test('google business seeds event dates in the user time zone and an offer drops the event times', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::GoogleBusiness, ContentType::GoogleBusinessPost);
    $timezone = now('UTC')->hour >= 12 ? 'Pacific/Kiritimati' : 'Pacific/Pago_Pago';
    $postPlatform->post->user->update(['timezone' => $timezone]);
    $this->actingAs($postPlatform->post->user->fresh());
    $today = now($timezone);

    expect($today->format('Y-m-d'))->not->toBe(now('UTC')->format('Y-m-d'));

    $page = visit(route('app.posts.edit', $postPlatform->post))->withTimezone('UTC')->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'google-business-topic-EVENT');
    $page->click('@google-business-topic-EVENT');
    waitForChannelSettingsTestId($page, 'google-business-add-time');
    $page->fill('@google-business-title', 'Launch night')
        ->click('@google-business-add-time');
    waitForChannelSettingsTestId($page, 'google-business-start-time');
    $page->fill('@google-business-start-time', '18:30')
        ->fill('@google-business-end-time', '21:00')
        ->click('@google-business-topic-OFFER');
    waitForChannelSettingsTestId($page, 'google-business-add-details');

    $page->assertMissing('@google-business-start-time')
        ->assertNoJavaScriptErrors()
        ->click('@composer-save-draft')
        ->assertMissing('@post-composer-dialog');

    $meta = $postPlatform->fresh()->meta;
    expect(data_get($meta, 'topic_type'))->toBe('OFFER')
        ->and(data_get($meta, 'event'))->toEqual([
            'title' => 'Launch night',
            'start_date' => $today->format('Y-m-d'),
            'end_date' => $today->addDays(7)->format('Y-m-d'),
            'start_time' => null,
            'end_time' => null,
        ]);
});

test('the youtube card shows its single short type as a checked radio and a single-type network shows none', function () {
    fakeChannelSettingsApis();
    $youtube = seedChannelSettingsPost(Platform::YouTube, ContentType::YouTubeShort);

    $page = visit(route('app.posts.edit', $youtube->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-type-{$youtube->social_account_id}-youtube_short");

    expect($page->script("document.querySelector('[data-testid=\"composer-type-{$youtube->social_account_id}-youtube_short\"]').getAttribute('data-state')"))->toBe('checked');
    $page->assertSeeIn("@composer-type-{$youtube->social_account_id}", 'Short')
        ->assertNoJavaScriptErrors();

    $linkedin = seedChannelSettingsPost(Platform::LinkedIn, ContentType::LinkedInPost);
    $page = visit(route('app.posts.edit', $linkedin->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'composer-customization');
    $page->assertMissing("@composer-type-{$linkedin->social_account_id}")
        ->assertMissing("@composer-type-{$linkedin->social_account_id}-linkedin_post")
        ->assertNoJavaScriptErrors();
});

test('the instagram card shows share to feed on reels, the AI label last and on every type', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Instagram, ContentType::InstagramReel);
    $id = $postPlatform->social_account_id;

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'instagram-share-to-feed');

    expect($page->script("document.querySelector('[data-testid=\"instagram-share-to-feed\"]').getAttribute('aria-checked')"))->toBe('true')
        ->and($page->script(<<<'JS'
            (() => {
                const sections = document.querySelectorAll('[data-testid="composer-customization"] [data-testid="channel-settings-rows"]');
                return !!sections[sections.length - 1].querySelector('[data-testid="instagram-ai-generated"]');
            })()
        JS))->toBeTrue();

    $page->click('@instagram-share-to-feed')
        ->click('@instagram-ai-generated')
        ->click("@composer-type-{$id}-instagram_story");
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"instagram-share-to-feed\"]')");
    waitForChannelSettingsTestId($page, 'instagram-ai-generated');
    $page->click("@composer-type-{$id}-instagram_feed");
    waitForChannelSettingsTestId($page, 'instagram-ai-generated');
    $page->assertMissing('@instagram-share-to-feed')
        ->click("@composer-type-{$id}-instagram_reel");
    waitForChannelSettingsTestId($page, 'instagram-share-to-feed');
    $page->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');

    expect($postPlatform->fresh()->meta)->toEqual([
        'share_to_feed' => false,
        'is_ai_generated' => true,
    ]);
});

test('the instagram card counts hashtags down to five and warns past the limit', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Instagram, ContentType::InstagramFeed);
    $id = $postPlatform->social_account_id;

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-hashtags-remaining-{$id}");

    $page->fill("@composer-caption-{$id}", 'Launch #a #b #c')
        ->assertSeeIn("@composer-hashtags-remaining-{$id}", '2')
        ->assertMissing("@composer-hashtag-warning-{$id}")
        ->fill("@composer-caption-{$id}", 'Launch #a #b #c #d #e #f');
    waitForChannelSettingsTestId($page, "composer-hashtag-warning-{$id}");

    $page->assertMissing("@composer-hashtags-remaining-{$id}")
        ->assertSeeIn("@composer-hashtag-warning-{$id}", 'Instagram only allows 5 hashtags per post.')
        ->fill("@composer-caption-{$id}", 'Launch #a #b #c #d #e');
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"composer-hashtag-warning-{$id}\"]')");

    $page->assertSeeIn("@composer-hashtags-remaining-{$id}", '0')
        ->assertNoJavaScriptErrors();
});

test('an instagram story is not blocked by the hashtag limit while a feed post is', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Instagram, ContentType::InstagramStory);
    $id = $postPlatform->social_account_id;
    $image = Media::factory()->temporaryUpload($postPlatform->post->workspace)->create(['mime_type' => 'image/png', 'meta' => ['width' => 1080, 'height' => 1920]]);
    $postPlatform->post->update(['content' => 'Launch #a #b #c #d #e #f', 'media' => [MediaItem::fromMedia($image)->toArray()]]);
    $blocked = "document.querySelector('[data-testid=\"composer-submit\"]').getAttribute('aria-disabled') === 'true'";

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-{$id}-media-item-0");

    expect($page->script($blocked))->toBeFalse();

    $page->click("@composer-type-{$id}-instagram_feed");
    waitForChannelSettingsTestId($page, "composer-hashtag-warning-{$id}");

    expect($page->script($blocked))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the main post counter counts an emoji as one character', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Mastodon, ContentType::MastodonPost);
    $id = $postPlatform->social_account_id;
    $limit = Platform::Mastodon->maxContentLength();

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-char-count-{$id}");

    $page->fill("@composer-caption-{$id}", '😀😀😀');
    waitForChannelSettingsCondition($page, "document.querySelector('[data-testid=\"composer-char-count-{$id}\"]')?.textContent.trim() === '".($limit - 3)."'");

    $page->assertSeeIn("@composer-char-count-{$id}", (string) ($limit - 3))
        ->assertNoJavaScriptErrors();
});

test('the mastodon content warning counts against the post', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Mastodon, ContentType::MastodonPost);
    $id = $postPlatform->social_account_id;
    $limit = Platform::Mastodon->maxContentLength();

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'mastodon-content-warning');

    $page->assertSeeIn("@composer-char-count-{$id}", (string) ($limit - mb_strlen('Channel settings')))
        ->fill('@mastodon-content-warning', 'Spoilers')
        ->assertSeeIn("@composer-char-count-{$id}", (string) ($limit - mb_strlen('Channel settings') - mb_strlen('Spoilers')))
        ->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');

    expect($postPlatform->fresh()->meta)->toEqual(['spoiler_text' => 'Spoilers']);
});

test('the mastodon content warning is counted in code points', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Mastodon, ContentType::MastodonPost);
    $id = $postPlatform->social_account_id;
    $limit = Platform::Mastodon->maxContentLength();

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'mastodon-content-warning');

    $page->fill('@mastodon-content-warning', '😀😀')
        ->assertSeeIn("@composer-char-count-{$id}", (string) ($limit - mb_strlen('Channel settings') - 2))
        ->assertNoJavaScriptErrors();
});

test('the threads card offers a topic and a ghost post drops the topic and the media tray', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Threads, ContentType::ThreadsPost);
    $id = $postPlatform->social_account_id;

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'threads-topic-tag');

    $page->fill('@threads-topic-tag', '#laravel')
        ->assertPresent("@composer-{$id}-dropzone")
        ->click("@composer-type-{$id}-threads_ghost_post");
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"threads-topic-tag\"]')");
    $page->assertMissing("@composer-{$id}-dropzone")
        ->click("@composer-type-{$id}-threads_post");
    waitForChannelSettingsTestId($page, 'threads-topic-tag');
    $page->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');

    expect($postPlatform->fresh()->meta)->toEqual(['topic_tag' => 'laravel']);
});

test('ghost post is disabled while the threads card has media', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Threads, ContentType::ThreadsPost);
    $id = $postPlatform->social_account_id;
    $ghost = "composer-type-{$id}-threads_ghost_post";
    $image = Media::factory()->temporaryUpload($postPlatform->post->workspace)->create(['mime_type' => 'image/png']);
    $postPlatform->post->update(['content' => 'With a photo', 'media' => [MediaItem::fromMedia($image)->toArray()]]);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-{$id}-media-item-0");

    expect($page->script("document.querySelector('[data-testid=\"{$ghost}\"]').disabled"))->toBeTrue();
    $page->hover("@{$ghost}-label");
    waitForChannelSettingsTestId($page, "{$ghost}-reason");
    $page->assertSeeIn("@{$ghost}-reason", "Ghost Posts don't support attachments");

    $page->click("@composer-{$id}-remove-0");
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"{$ghost}\"]').disabled");

    $page->click("@{$ghost}")
        ->assertNoJavaScriptErrors();
});

test('ghost post is disabled while the threads card shows a link card and never fetches one', function () {
    $url = 'https://93.184.216.34/article';
    Http::fake([
        $url => Http::response('<meta property="og:title" content="Article card">'),
        '*' => Http::response([], 200),
    ]);
    $postPlatform = seedChannelSettingsPost(Platform::Threads, ContentType::ThreadsPost);
    $id = $postPlatform->social_account_id;
    $ghost = "composer-type-{$id}-threads_ghost_post";
    $postPlatform->post->update(['content' => "Read {$url}"]);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-link-card-{$id}");

    expect($page->script("document.querySelector('[data-testid=\"{$ghost}\"]').disabled"))->toBeTrue();
    $page->hover("@{$ghost}-label");
    waitForChannelSettingsTestId($page, "{$ghost}-reason");
    $page->assertSeeIn("@{$ghost}-reason", "Ghost Posts don't support attachments");

    $page->fill("@composer-caption-{$id}", 'No link any more');
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"{$ghost}\"]').disabled");

    $page->click("@{$ghost}")
        ->fill("@composer-caption-{$id}", "Read {$url}");
    waitForChannelSettingsCondition($page, "document.querySelector('[data-testid=\"composer-link-card-{$id}\"]')");
    $page->assertMissing("@composer-link-card-{$id}")
        ->assertNoJavaScriptErrors();
});

test('the threads preview shows a ghost post as a dashed bubble that expires in 24 hours', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Threads, ContentType::ThreadsPost);
    $id = $postPlatform->social_account_id;

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'threads-preview');
    $page->assertMissing('@threads-ghost-bubble')
        ->click("@composer-type-{$id}-threads_ghost_post");
    waitForChannelSettingsTestId($page, 'threads-ghost-bubble');

    $page->assertSeeIn('@threads-ghost-bubble', 'Channel settings')
        ->assertSeeIn('@threads-ghost-expiry', '24h left')
        ->click("@composer-type-{$id}-threads_post");
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"threads-ghost-bubble\"]')");
    $page->assertMissing('@threads-ghost-bubble')
        ->assertNoJavaScriptErrors();
});

test('a ghost post that got media shows it on the card with a text only warning until it is removed', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Threads, ContentType::ThreadsGhostPost);
    $id = $postPlatform->social_account_id;
    $image = Media::factory()->temporaryUpload($postPlatform->post->workspace)->create(['mime_type' => 'image/png']);
    $postPlatform->post->update(['media' => [MediaItem::fromMedia($image)->toArray()]]);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-text-only-warning-{$id}");

    $page->assertSeeIn("@composer-text-only-warning-{$id}", __('posts.form.warnings.text_only'))
        ->assertPresent("@composer-{$id}-media-item-0")
        ->click("@composer-{$id}-remove-0");
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"composer-text-only-warning-{$id}\"]')");
    $page->assertMissing("@composer-{$id}-dropzone")
        ->assertNoJavaScriptErrors();
});

test('typing a link into a ghost post shows the text only warning on the card', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Threads, ContentType::ThreadsGhostPost);
    $id = $postPlatform->social_account_id;

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, "composer-caption-{$id}");
    $page->assertMissing("@composer-text-only-warning-{$id}")
        ->fill("@composer-caption-{$id}", 'Read https://example.com/article');
    waitForChannelSettingsTestId($page, "composer-text-only-warning-{$id}");

    $page->assertSeeIn("@composer-text-only-warning-{$id}", __('posts.form.warnings.text_only'))
        ->fill("@composer-caption-{$id}", 'Just text');
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"composer-text-only-warning-{$id}\"]')");
    $page->assertMissing("@composer-text-only-warning-{$id}")
        ->assertNoJavaScriptErrors();
});

test('a bluesky thread starts from the toolbar and stacks its replies under the post', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Bluesky, ContentType::BlueskyPost);
    $id = $postPlatform->social_account_id;
    $limit = Platform::Bluesky->maxContentLength();

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'thread-start');

    expect($page->script("!!document.querySelector('[data-testid=\"composer-{$id}-toolbar\"] [data-testid=\"thread-start\"]')"))->toBeTrue();

    $page->click('@thread-start');
    waitForChannelSettingsTestId($page, 'thread-reply-0');
    $page->assertVisible("@composer-{$id}-caption-collapsed")
        ->fill('@thread-reply-0', 'Second post')
        ->assertSeeIn("@composer-char-count-{$id}", (string) ($limit - mb_strlen('Second post')))
        ->click('@thread-add-reply');
    waitForChannelSettingsTestId($page, 'thread-reply-1');
    $page->fill('@thread-reply-1', 'Third post')
        ->assertVisible('@thread-reply-collapsed-0')
        ->assertSeeIn('@preview-thread-reply-1', 'Third post')
        ->click('@thread-reply-remove-0');
    waitForChannelSettingsCondition($page, "!document.querySelector('[data-testid=\"thread-reply-1\"], [data-testid=\"thread-reply-collapsed-1\"]')");
    $page->click("@composer-{$id}-caption-collapsed");
    waitForChannelSettingsTestId($page, "composer-caption-{$id}");
    $page->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft')->assertMissing('@post-composer-dialog');

    expect($postPlatform->fresh()->meta)->toEqual(['thread_replies' => ['Third post']]);
});

test('a mastodon thread reply counts the content warning against its own limit', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Mastodon, ContentType::MastodonPost);
    $postPlatform->update(['meta' => ['spoiler_text' => 'Spoilers']]);
    $id = $postPlatform->social_account_id;
    $limit = Platform::Mastodon->maxContentLength();

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'thread-start');

    $page->click('@thread-start');
    waitForChannelSettingsTestId($page, 'thread-reply-0');
    $page->fill('@thread-reply-0', 'Reply')
        ->assertSeeIn("@composer-char-count-{$id}", (string) ($limit - mb_strlen('Spoilers') - mb_strlen('Reply')))
        ->assertSeeIn('@preview-thread-reply-0', 'Spoilers')
        ->assertNoJavaScriptErrors();
});

test('start thread is not offered on networks that cannot chain', function (Platform $platform, ContentType $contentType) {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost($platform, $contentType);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'composer-customization');
    $page->assertMissing('@thread-start')->assertNoJavaScriptErrors();
})->with([
    'x until task 19' => [Platform::X, ContentType::XPost],
    'threads' => [Platform::Threads, ContentType::ThreadsPost],
]);

test('a thread reply counts emoji as one character each like the server', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Bluesky, ContentType::BlueskyPost);
    $id = $postPlatform->social_account_id;
    $reply = str_repeat('😀', 160);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'thread-start');
    $page->click('@thread-start');
    waitForChannelSettingsTestId($page, 'thread-reply-0');
    $page->fill('@thread-reply-0', $reply)
        ->assertSeeIn("@composer-char-count-{$id}", (string) (Platform::Bluesky->maxContentLength() - 160));

    expect($page->script('document.querySelector(\'[data-testid="composer-submit"]\').getAttribute(\'aria-disabled\')'))->toBeNull();
    $page->assertNoJavaScriptErrors();
});

test('a thread reply of invisible characters is empty like on the server', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Bluesky, ContentType::BlueskyPost);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'thread-start');
    $page->click('@thread-start');
    waitForChannelSettingsTestId($page, 'thread-reply-0');
    $page->fill('@thread-reply-0', "\u{200B}\u{00A0}");
    waitForChannelSettingsCondition($page, "document.querySelector('[data-testid=\"composer-submit\"]')?.getAttribute('aria-disabled') === 'true'");

    expect($page->script('document.querySelector(\'[data-testid="composer-submit"]\').getAttribute(\'aria-disabled\')'))->toBe('true');
    $page->assertNoJavaScriptErrors();
});

test('a server error on a thread reply shows under that reply', function () {
    fakeChannelSettingsApis();
    app()->instance(ContentSanitizer::class, new class extends ContentSanitizer
    {
        public function displayText(string $content, Platform $platform): string
        {
            return $content === 'Too long for the server' ? str_repeat('a', 400) : parent::displayText($content, $platform);
        }
    });
    $postPlatform = seedChannelSettingsPost(Platform::Bluesky, ContentType::BlueskyPost);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'thread-start');
    $page->click('@thread-start');
    waitForChannelSettingsTestId($page, 'thread-reply-0');
    $page->fill('@thread-reply-0', 'Fine')->click('@thread-add-reply');
    waitForChannelSettingsTestId($page, 'thread-reply-1');
    $page->fill('@thread-reply-1', 'Too long for the server')->click('@composer-submit');
    waitForChannelSettingsTestId($page, 'thread-reply-error-1');

    $page->assertSeeIn('@thread-reply-error-1', __('posts.form.thread.reply_too_long', ['limit' => 300, 'over' => 100]))
        ->assertMissing('@thread-reply-error-0')
        ->assertNoJavaScriptErrors();
});

test('editing a post that already has a thread shows its replies', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Bluesky, ContentType::BlueskyPost);
    $postPlatform->update(['meta' => ['thread_replies' => ['First reply', 'Second reply']]]);
    $id = $postPlatform->social_account_id;

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'thread-reply-collapsed-1');

    $page->assertSeeIn('@thread-reply-collapsed-0', 'First reply')
        ->assertSeeIn('@thread-reply-collapsed-1', 'Second reply')
        ->assertVisible("@composer-caption-{$id}")
        ->assertVisible('@thread-add-reply')
        ->assertMissing('@thread-start')
        ->assertSeeIn('@preview-thread', 'Second reply');

    expect($page->script('!!document.querySelector(\'[data-testid="preview-thread"] [data-testid="preview-thread-reply-1"]\')'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the mastodon preview hides the post behind its content warning on every post of the thread', function () {
    fakeChannelSettingsApis();
    $postPlatform = seedChannelSettingsPost(Platform::Mastodon, ContentType::MastodonPost);
    $postPlatform->update(['meta' => ['spoiler_text' => 'Spoilers', 'thread_replies' => ['Hidden reply']]]);

    $page = visit(route('app.posts.edit', $postPlatform->post))->resize(1280, 900);
    waitForChannelSettingsTestId($page, 'mastodon-preview-content-warning');

    expect($page->script("[...document.querySelectorAll('[data-testid=\"preview-thread\"] [data-testid=\"mastodon-preview-content-warning\"]')].map((warning) => warning.innerText.replace(/\\s+/g, ' ').trim())"))
        ->toBe(array_fill(0, 2, 'Spoilers '.__('posts.composer.preview.tap_to_reveal')));

    $page->assertDontSeeIn('@preview-thread', 'Channel settings')
        ->assertDontSeeIn('@preview-thread', 'Hidden reply')
        ->assertNoJavaScriptErrors();
});
