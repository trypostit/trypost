<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAltTextGenerator;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

/**
 * A draft whose images are the 200x200 quadrant fixture, inlined as data URLs so
 * the editor canvas can read their pixels without a signed storage URL.
 *
 * @return array{0: Post, 1: PostPlatform}
 */
function seedMediaEditorPost(ContentType $contentType = ContentType::InstagramFeed, int $imageCount = 1, ?string $firstMediaId = null): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Editing media',
        'media' => array_map(fn (int $index): array => [
            'id' => $index === 1 && $firstMediaId ? $firstMediaId : "image-{$index}",
            'type' => 'image',
            'mime_type' => 'image/png',
            'path' => "uploads/image-{$index}.png",
            'url' => "data:image/png;base64,{$base64}",
            'size' => 1024,
            'meta' => ['width' => 200, 'height' => 200],
        ], range(1, $imageCount)),
    ]);

    $platform = $contentType->platform();
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
        'scopes' => $platform->requiredPublishScopes(),
    ]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => $platform,
        'content_type' => $contentType,
    ]);

    test()->actingAs($user);

    return [$post, $postPlatform];
}

/**
 * Polls from the page until `$condition` holds (never sleep(): the test server
 * only ticks while Pest awaits Playwright).
 */
function waitForMediaEditor(mixed $page, string $condition): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < 100; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

function openMediaEditor(mixed $page, PostPlatform $postPlatform, int $index = 0): void
{
    openMediaEditorPanel($page, $postPlatform->social_account_id, $index);
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-selection\"]')?.getBoundingClientRect().width > 0");
}

/**
 * Opens the editor without waiting for the stage image, whose signed URL only the
 * Herd host serves.
 */
function openMediaEditorPanel(mixed $page, string $accountId, int $index = 0): void
{
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-{$accountId}-edit-{$index}\"]')");
    $page->click("@composer-{$accountId}-edit-{$index}");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"crop-aspect-freeform\"]')");
}

/**
 * @return list<string>
 */
function mediaEditorSegmentIds(mixed $page): array
{
    return $page->script('Array.from(document.querySelectorAll(\'[data-testid="media-editor-segments"] button\')).map((segment) => segment.dataset.testid)');
}

/**
 * @return list<string>
 */
function mediaEditorPresetIds(mixed $page): array
{
    return $page->script('Array.from(document.querySelectorAll(\'[data-testid^="crop-aspect-"]\')).map((preset) => preset.dataset.testid)');
}

function clickMediaEditorStage(mixed $page, float $x, float $y): void
{
    $page->script(<<<JS
        (() => {
            const stage = document.querySelector('[data-testid="media-editor-stage"]');
            const box = stage.getBoundingClientRect();
            stage.dispatchEvent(new MouseEvent('click', {
                bubbles: true,
                clientX: box.left + box.width * {$x},
                clientY: box.top + box.height * {$y},
            }));
        })();
    JS);
}

function saveMediaEditorDraft(mixed $page): void
{
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"media-editor\"]') && document.querySelector('[data-testid=\"composer-save-draft\"]')?.disabled === false");
    $page->click('@composer-save-draft');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')");
}

test('rotating an image uploads the rotated pixels', function () {
    [$post, $postPlatform] = seedMediaEditorPost();
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $page->click('@media-editor-rotate-right')
        ->assertSee('Apply changes to 1 item')
        ->click('@media-editor-apply');
    saveMediaEditorDraft($page);

    $source = imagecreatefrompng(base_path('tests/fixtures/crop-quadrants.png'));
    $owned = $post->ownedMedia()->sole();
    $rotated = imagecreatefromstring(Storage::get($owned->path));
    $bottomLeftBefore = imagecolorsforindex($source, imagecolorat($source, 50, 150));
    $topLeftAfter = imagecolorsforindex($rotated, imagecolorat($rotated, 50, 50));

    expect($post->fresh()->media[0]['id'])->toBe($owned->id)
        ->and($owned->collection)->toBe(Media::COLLECTION_MEDIA)
        ->and($owned->upload_token)->toBeNull()
        ->and(Media::where('collection', Media::COLLECTION_UPLOADS)->count())->toBe(0)
        ->and(imagesx($rotated))->toBe(200)
        ->and(abs($topLeftAfter['red'] - $bottomLeftBefore['red']))->toBeLessThan(8)
        ->and(abs($topLeftAfter['green'] - $bottomLeftBefore['green']))->toBeLessThan(8)
        ->and(abs($topLeftAfter['blue'] - $bottomLeftBefore['blue']))->toBeLessThan(8);
});

test('one apply saves edits made to several images', function () {
    [$post, $postPlatform] = seedMediaEditorPost(imageCount: 2);
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $page->click('@crop-aspect-1-1')
        ->click('@media-editor-thumb-1')
        ->click('@media-editor-alt-tab')
        ->fill('@media-editor-alt-text', 'Four coloured squares')
        ->assertSee('Apply changes to 2 items')
        ->click('@media-editor-apply');
    saveMediaEditorDraft($page);

    $media = $post->fresh()->media;
    expect($media[0]['id'])->not->toBe('image-1')
        ->and($media[1]['id'])->toBe('image-2')
        ->and($media[1]['meta']['alt_text'])->toBe('Four coloured squares');
});

test('people tagged on an Instagram image are saved with their position', function () {
    [$post, $postPlatform] = seedMediaEditorPost();
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $page->click('@media-editor-tags-tab')
        ->click('@media-editor-tags-start');
    clickMediaEditorStage($page, 0.25, 0.75);
    $page->fill('@media-editor-tag-username', 'not a username')
        ->click('@media-editor-tag-save')
        ->assertVisible('@media-editor-tag-username')
        ->fill('@media-editor-tag-username', '@paulo.castellano')
        ->click('@media-editor-tag-save')
        ->assertVisible('@media-editor-tag-0')
        ->click('@media-editor-apply');
    saveMediaEditorDraft($page);

    $tag = $post->fresh()->media[0]['meta']['user_tags'][0];
    expect($tag['username'])->toBe('paulo.castellano')
        ->and($tag['x'])->toEqualWithDelta(0.25, 0.02)
        ->and($tag['y'])->toEqualWithDelta(0.75, 0.02)
        ->and($post->fresh()->media[0]['id'])->toBe('image-1');
});

test('alt text can be generated with AI for a post image', function () {
    MediaAltTextGenerator::fake(['Four coloured squares in a grid.']);
    [$post, $postPlatform] = seedMediaEditorPost(firstMediaId: '00000000-0000-4000-8000-000000000001');
    Media::factory()->ownedByPost($post)->create(['id' => '00000000-0000-4000-8000-000000000001']);
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $page->click('@media-editor-alt-tab')
        ->click('@media-editor-alt-generate');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-alt-text\"]')?.value.length > 0");

    expect($page->script('document.querySelector("[data-testid=media-editor-alt-text]").value'))
        ->toBe('Four coloured squares in a grid.');
    $page->assertNoJavaScriptErrors();
});

test('a TikTok photo offers no segments and TikTok\'s presets in order', function () {
    [$post, $postPlatform] = seedMediaEditorPost(ContentType::TikTokPhoto);
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    expect(mediaEditorPresetIds($page))->toBe([
        'crop-aspect-freeform', 'crop-aspect-original', 'crop-aspect-4-3',
        'crop-aspect-16-9', 'crop-aspect-9-16', 'crop-aspect-1-1',
    ]);
    $page->assertMissing('@media-editor-segments')
        ->assertMissing('@media-editor-edit-tab')
        ->assertNoJavaScriptErrors();
});

test('an X post offers Edit and Alt Text with X\'s presets in order', function () {
    [$post, $postPlatform] = seedMediaEditorPost(ContentType::XPost);
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    expect(mediaEditorPresetIds($page))->toBe([
        'crop-aspect-freeform', 'crop-aspect-original', 'crop-aspect-1-1',
        'crop-aspect-4-3', 'crop-aspect-16-9', 'crop-aspect-2-1',
    ]);
    expect(mediaEditorSegmentIds($page))->toBe(['media-editor-edit-tab', 'media-editor-alt-tab']);
    $page->assertNoJavaScriptErrors();
});

test('each channel\'s editor shows that channel\'s presets', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id]);
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);
    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));

    $page = visit(route('app.posts.create'));
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-add-account\"]')?.getBoundingClientRect().height > 0");
    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$tiktok->id}")
        ->click("@composer-account-option-{$x->id}");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-file-input\"]')");
    $page->script(<<<JS
        (() => {
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const input = document.querySelector('[data-testid="composer-file-input"]');
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'quadrants.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-media-item\"]')");
    $page->click('@composer-next');

    $page->click("@composer-account-{$tiktok->id}");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-type-{$tiktok->id}-tiktok_photo\"]')");
    $page->click("@composer-type-{$tiktok->id}-tiktok_photo");
    openMediaEditorPanel($page, $tiktok->id);
    expect(mediaEditorPresetIds($page))->toBe([
        'crop-aspect-freeform', 'crop-aspect-original', 'crop-aspect-4-3',
        'crop-aspect-16-9', 'crop-aspect-9-16', 'crop-aspect-1-1',
    ]);
    $page->assertMissing('@media-editor-segments')
        ->click('@media-editor-cancel');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"media-editor\"]')");

    $page->click("@composer-account-{$x->id}");
    openMediaEditorPanel($page, $x->id);
    expect(mediaEditorPresetIds($page))->toBe([
        'crop-aspect-freeform', 'crop-aspect-original', 'crop-aspect-1-1',
        'crop-aspect-4-3', 'crop-aspect-16-9', 'crop-aspect-2-1',
    ]);
    $page->assertVisible('@media-editor-alt-tab')
        ->assertNoJavaScriptErrors();
});

test('an Instagram feed post offers Edit, Alt Text and Tag People with its presets', function () {
    [$post, $postPlatform] = seedMediaEditorPost(ContentType::InstagramFeed);
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    expect(mediaEditorPresetIds($page))->toBe([
        'crop-aspect-freeform', 'crop-aspect-original', 'crop-aspect-3-4',
        'crop-aspect-4-5', 'crop-aspect-1-1', 'crop-aspect-1-91-1',
    ]);
    expect(mediaEditorSegmentIds($page))->toBe(['media-editor-edit-tab', 'media-editor-alt-tab', 'media-editor-tags-tab']);
    $page->assertNoJavaScriptErrors();
});

test('an Instagram story offers no tagging and only the vertical preset', function () {
    [$post, $postPlatform] = seedMediaEditorPost(ContentType::InstagramStory);
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    expect(mediaEditorPresetIds($page))->toBe([
        'crop-aspect-freeform', 'crop-aspect-original', 'crop-aspect-9-16',
    ]);
    $page->assertMissing('@media-editor-tags-tab')
        ->assertNoJavaScriptErrors();
});

test('a Pinterest pin offers its presets', function () {
    [$post, $postPlatform] = seedMediaEditorPost(ContentType::PinterestPin);
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    expect(mediaEditorPresetIds($page))->toBe([
        'crop-aspect-freeform', 'crop-aspect-original', 'crop-aspect-2-3', 'crop-aspect-1-1',
    ]);
    $page->assertNoJavaScriptErrors();
});

test('center and reset only enable once the geometry changes', function () {
    [$post, $postPlatform] = seedMediaEditorPost();
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $isDisabled = fn (string $testId): bool => $page->script("document.querySelector('[data-testid=\"{$testId}\"]').disabled");

    expect($isDisabled('media-editor-center'))->toBeTrue()
        ->and($isDisabled('media-editor-reset'))->toBeTrue();

    $page->click('@crop-aspect-1-1')
        ->drag('@media-editor-handle-nw', '@media-editor-stage');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-center\"]').disabled === false");

    expect($isDisabled('media-editor-center'))->toBeFalse()
        ->and($isDisabled('media-editor-reset'))->toBeFalse();

    $page->click('@media-editor-reset');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-reset\"]').disabled === true");

    expect($isDisabled('media-editor-center'))->toBeTrue()
        ->and($isDisabled('media-editor-reset'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

test('the stage only takes tags while tagging', function () {
    [$post, $postPlatform] = seedMediaEditorPost();
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $page->click('@media-editor-tags-tab');
    clickMediaEditorStage($page, 0.5, 0.5);
    $page->assertMissing('@media-editor-tag-point')
        ->assertMissing('@media-editor-tag-form')
        ->click('@media-editor-tags-start')
        ->assertVisible('@media-editor-tags-finish');

    clickMediaEditorStage($page, 0.5, 0.5);
    $page->assertVisible('@media-editor-tag-form')
        ->fill('@media-editor-tag-username', 'paulo')
        ->click('@media-editor-tag-save')
        ->assertVisible('@media-editor-tag-0');

    clickMediaEditorStage($page, 0.25, 0.25);
    $page->assertVisible('@media-editor-tag-form')
        ->click('@media-editor-tags-finish')
        ->assertMissing('@media-editor-tag-form')
        ->assertVisible('@media-editor-tags-start')
        ->assertNoJavaScriptErrors();
});

test('switching tabs ends tagging mode', function () {
    [$post, $postPlatform] = seedMediaEditorPost();
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $page->click('@media-editor-tags-tab')
        ->click('@media-editor-tags-start')
        ->assertVisible('@media-editor-tags-finish')
        ->click('@media-editor-alt-tab')
        ->click('@media-editor-tags-tab')
        ->assertVisible('@media-editor-tags-start')
        ->assertMissing('@media-editor-tags-finish');

    clickMediaEditorStage($page, 0.5, 0.5);
    $page->assertMissing('@media-editor-tag-form')
        ->assertNoJavaScriptErrors();
});

test('closing with the × discards edits like Cancel', function () {
    [$post, $postPlatform] = seedMediaEditorPost();
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $page->click('@media-editor-rotate-right')
        ->assertSee('Apply changes to 1 item')
        ->click('@media-editor-close');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"media-editor\"]')");
    $page->assertMissing('@media-editor');

    openMediaEditor($page, $postPlatform);
    $page->assertDisabled('@media-editor-apply')
        ->assertDisabled('@media-editor-reset')
        ->click('@media-editor-close');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"media-editor\"]')");

    expect($post->fresh()->media[0]['id'])->toBe('image-1');
    $page->assertNoJavaScriptErrors();
});

test('at 20 tags tagging cannot start and the stage opens no form', function () {
    [$post, $postPlatform] = seedMediaEditorPost();
    $media = $post->media;
    $media[0]['meta']['user_tags'] = array_map(
        fn (int $index): array => ['username' => "person{$index}", 'x' => 0.5, 'y' => 0.5],
        range(1, 20),
    );
    $post->update(['media' => $media]);
    $page = visit(route('app.posts.edit', $post));
    openMediaEditor($page, $postPlatform);

    $page->click('@media-editor-tags-tab')
        ->assertDisabled('@media-editor-tags-start');
    clickMediaEditorStage($page, 0.25, 0.25);
    $page->assertMissing('@media-editor-tag-point')
        ->assertMissing('@media-editor-tag-form')
        ->assertNoJavaScriptErrors();
});

/**
 * Opens a new post with the given accounts selected and uploads the files into
 * the shared list (step 1). Image `src` values pointing at storage get the
 * fixture bytes (the private local disk serves no unsigned URL); the value Vue
 * set is kept in `data-original-src`.
 *
 * @param  list<SocialAccount>  $accounts
 * @param  list<array{name: string, type: string, base64: string}>  $files
 */
function openMediaEditorComposer(User $user, array $accounts, array $files): mixed
{
    test()->actingAs($user);
    $fixture = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page = visit(route('app.posts.create'));
    waitForMediaEditor($page, <<<'JS'
        (() => {
            const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
            return sheet?.getAttribute('data-state') === 'open'
                && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                && document.querySelector('[data-testid="composer-add-account"]')?.getBoundingClientRect().height > 0;
        })()
    JS);
    $page->fill('@composer-base-content', 'Editing shared media');

    $encodedFiles = json_encode($files);
    $page->script(<<<JS
        (() => {
            const fixture = 'data:image/png;base64,{$fixture}';
            const property = Object.getOwnPropertyDescriptor(HTMLImageElement.prototype, 'src');
            Object.defineProperty(HTMLImageElement.prototype, 'src', {
                configurable: true,
                get() { return property.get.call(this); },
                set(value) {
                    this.dataset.originalSrc = value;
                    return property.set.call(this, String(value).includes('/storage/') ? fixture : value);
                },
            });
            const input = document.querySelector('[data-testid="composer-file-input"]');
            const transfer = new DataTransfer();
            for (const file of {$encodedFiles}) {
                const bytes = Uint8Array.from(atob(file.base64), (character) => character.charCodeAt(0));
                transfer.items.add(new File([bytes], file.name, { type: file.type }));
            }
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
    $count = count($files);
    waitForMediaEditor($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === {$count} && !document.querySelector('[data-testid=\"composer-upload-item\"]')");
    if ($accounts !== []) {
        $page->click('@composer-add-account');
        foreach ($accounts as $account) {
            $page->click("@composer-account-option-{$account->id}");
        }
        $prefix = count($accounts) === 1 ? "composer-{$accounts[0]->id}" : 'composer';
        waitForMediaEditor($page, "document.querySelectorAll('[data-testid=\"{$prefix}-media-item\"]').length === {$count}");
    }

    return $page;
}

/**
 * @return array{name: string, type: string, base64: string}
 */
function mediaEditorPng(string $name): array
{
    return ['name' => $name, 'type' => 'image/png', 'base64' => base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')))];
}

function mediaEditorComposerUser(): User
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    return $user;
}

/**
 * @return list<string>
 */
function mediaEditorTileButtons(mixed $page, string $prefix, int $index): array
{
    return $page->script(<<<JS
        Array.from(document.querySelectorAll('[data-testid="{$prefix}-media-item-{$index}"] button'))
            .map((button) => button.dataset.testid)
            .filter((testId) => !testId.includes('-drag-handle-'))
    JS);
}

function mediaEditorTileSrc(mixed $page, string $prefix, int $index): ?string
{
    return $page->script("document.querySelector('[data-testid=\"{$prefix}-media-item-{$index}\"] img')?.dataset.originalSrc ?? null");
}

test('step 1 tiles offer the buttons the selected channels support', function () {
    $user = mediaEditorComposerUser();
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $user->current_workspace_id]);
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [$instagram, $x], [
        mediaEditorPng('photo.png'),
        ['name' => 'animation.gif', 'type' => 'image/gif', 'base64' => 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'],
        ['name' => 'brochure.pdf', 'type' => 'application/pdf', 'base64' => base64_encode("%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF")],
    ]);

    expect(mediaEditorTileButtons($page, 'composer', 0))->toBe(['composer-remove-0', 'composer-tag-0', 'composer-alt-0', 'composer-edit-0'])
        ->and(mediaEditorTileButtons($page, 'composer', 1))->toBe(['composer-remove-1'])
        ->and(mediaEditorTileButtons($page, 'composer', 2))->toBe(['composer-remove-2']);
    $page->assertNoJavaScriptErrors();
});

test('step 1 tiles offer only the pencil for a TikTok image', function () {
    $user = mediaEditorComposerUser();
    $tiktok = SocialAccount::factory()->tiktok()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [$tiktok], [mediaEditorPng('photo.png')]);

    expect(mediaEditorTileButtons($page, "composer-{$tiktok->id}", 0))->toBe(["composer-{$tiktok->id}-remove-0", "composer-{$tiktok->id}-edit-0"]);
    $page->assertNoJavaScriptErrors();
});

test('the ALT button opens the editor on the Alt Text segment', function () {
    $user = mediaEditorComposerUser();
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [$instagram], [mediaEditorPng('photo.png')]);

    $page->click("@composer-{$instagram->id}-alt-0");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-alt-text\"]')");

    expect($page->script("document.querySelector('[data-testid=\"media-editor-alt-tab\"]').getAttribute('aria-pressed')"))->toBe('true');
    $page->assertVisible('@media-editor-alt-text')
        ->assertNoJavaScriptErrors();
});

/**
 * Opens the ALT editor of a tile, types `$altText` and applies it.
 */
function applyMediaEditorAltText(mixed $page, string $prefix, string $altText): void
{
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"{$prefix}-alt-0\"]')");
    $page->click("@{$prefix}-alt-0");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-alt-text\"]')");
    $page->fill('@media-editor-alt-text', $altText);
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-apply\"]')?.disabled === false");
    $page->click('@media-editor-apply');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"media-editor\"]')");
}

/**
 * @return array<string, ?string>
 */
function mediaEditorSavedAltTexts(SocialAccount ...$accounts): array
{
    return collect($accounts)->mapWithKeys(fn (SocialAccount $account): array => [
        $account->id => data_get(Post::query()
            ->whereIn('id', PostPlatform::query()->where('social_account_id', $account->id)->pluck('post_id'))
            ->sole()->media, '0.meta.alt_text'),
    ])->all();
}

test('alt text is offered only once a channel is selected', function () {
    $user = mediaEditorComposerUser();
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [], [mediaEditorPng('photo.png')]);

    expect(mediaEditorTileButtons($page, 'composer', 0))->toBe(['composer-remove-0', 'composer-edit-0']);
    $page->click('@composer-edit-0');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"crop-aspect-freeform\"]')");
    $page->assertMissing('@media-editor-alt-tab')
        ->click('@media-editor-cancel');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"media-editor\"]')");

    if (! $page->script("Boolean(document.querySelector('[data-testid=\"composer-account-option-{$linkedin->id}\"]'))")) {
        $page->click('@composer-add-account');
    }
    $page->click("@composer-account-option-{$linkedin->id}");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-{$linkedin->id}-alt-0\"]')");

    expect(mediaEditorTileButtons($page, "composer-{$linkedin->id}", 0))->toBe(["composer-{$linkedin->id}-remove-0", "composer-{$linkedin->id}-alt-0", "composer-{$linkedin->id}-edit-0"]);
    $page->assertNoJavaScriptErrors();
});

test('a step 1 alt text is saved on every channel', function () {
    $user = mediaEditorComposerUser();
    $first = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $second = SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [$first, $second], [mediaEditorPng('photo.png')]);

    applyMediaEditorAltText($page, 'composer', 'Four coloured squares');
    saveMediaEditorDraft($page);

    expect(mediaEditorSavedAltTexts($first, $second))->toBe([
        $first->id => 'Four coloured squares',
        $second->id => 'Four coloured squares',
    ]);
});

test('a step 2 alt text stays on its channel and the others keep the shared one', function () {
    $user = mediaEditorComposerUser();
    $first = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $second = SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [$first, $second], [mediaEditorPng('photo.png')]);

    applyMediaEditorAltText($page, 'composer', 'Shared description');
    $page->click('@composer-next')
        ->click("@composer-account-{$first->id}");
    applyMediaEditorAltText($page, "composer-{$first->id}", 'First channel description');

    $page->click("@composer-account-{$second->id}");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-{$second->id}-alt-0\"]')");
    $page->click("@composer-{$second->id}-alt-0");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-alt-text\"]')");
    expect($page->script("document.querySelector('[data-testid=\"media-editor-alt-text\"]').value"))->toBe('Shared description');
    $page->click('@media-editor-cancel');
    saveMediaEditorDraft($page);

    expect(mediaEditorSavedAltTexts($first, $second))->toBe([
        $first->id => 'First channel description',
        $second->id => 'Shared description',
    ]);
});

test('a step 1 edit replaces the shared item in place and every post owns its own file', function () {
    $user = mediaEditorComposerUser();
    $first = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $second = SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [$first, $second], [mediaEditorPng('first.png'), mediaEditorPng('second.png')]);
    $originals = Media::query()->where('collection', Media::COLLECTION_UPLOADS)->orderBy('original_filename')->get()->keyBy('original_filename');
    $firstSrc = mediaEditorTileSrc($page, 'composer', 0);
    $secondSrc = mediaEditorTileSrc($page, 'composer', 1);

    $page->click('@composer-edit-1');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-selection\"]')?.getBoundingClientRect().width > 0");
    $page->click('@media-editor-rotate-right');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-apply\"]')?.disabled === false");
    $page->click('@media-editor-apply');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"media-editor\"]') && document.querySelector('[data-testid=\"composer-save-draft\"]')?.disabled === false");

    expect(mediaEditorTileSrc($page, 'composer', 0))->toBe($firstSrc)
        ->and(mediaEditorTileSrc($page, 'composer', 1))->not->toBe($secondSrc)
        ->and(mediaEditorTileSrc($page, 'composer', 1))->not->toBeNull();

    $page->click('@composer-save-draft');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')");

    $posts = Post::query()->whereIn('id', PostPlatform::query()->whereIn('social_account_id', [$first->id, $second->id])->pluck('post_id'))->get();
    expect($posts)->toHaveCount(2);
    $paths = [];
    foreach ($posts as $post) {
        $owned = $post->ownedMedia()->orderBy('order')->get();
        expect($owned)->toHaveCount(2)
            ->and($owned[1]->path)->not->toBe($originals['second.png']->path)
            ->and($owned[1]->original_filename)->not->toBe('second.png');
        $paths = [...$paths, ...$owned->pluck('path')->all()];
    }
    expect(array_unique($paths))->toHaveCount(4);
});

test('a step 2 edit replaces only that channel\'s item and leaves the shared list alone', function () {
    $user = mediaEditorComposerUser();
    $first = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $second = SocialAccount::factory()->x()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [$first, $second], [mediaEditorPng('shared.png')]);
    $sharedSrc = mediaEditorTileSrc($page, 'composer', 0);

    $page->click('@composer-next')
        ->click("@composer-account-{$first->id}");
    openMediaEditor($page, PostPlatform::make(['social_account_id' => $first->id]));
    $page->click('@media-editor-rotate-right');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-apply\"]')?.disabled === false");
    $page->click('@media-editor-apply');
    waitForMediaEditor($page, "!document.querySelector('[data-testid=\"media-editor\"]') && document.querySelector('[data-testid=\"composer-save-draft\"]')?.disabled === false");

    expect(mediaEditorTileSrc($page, "composer-{$first->id}", 0))->not->toBe($sharedSrc);

    $page->click("@composer-account-{$second->id}");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-{$second->id}-media-item-0\"]')");
    expect(mediaEditorTileSrc($page, "composer-{$second->id}", 0))->toBe($sharedSrc);

    $page->click('@composer-back');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-back-confirm-go\"]')");
    $page->click('@composer-back-confirm-go');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-media-item-0\"]')");
    expect(mediaEditorTileSrc($page, 'composer', 0))->toBe($sharedSrc);
    $page->assertNoJavaScriptErrors();
});

test('a failed edit upload shows the inline error and keeps the original tile', function () {
    $user = mediaEditorComposerUser();
    $linkedin = SocialAccount::factory()->linkedin()->create(['workspace_id' => $user->current_workspace_id]);
    $page = openMediaEditorComposer($user, [$linkedin], [mediaEditorPng('photo.png')]);
    $originalSrc = mediaEditorTileSrc($page, "composer-{$linkedin->id}", 0);
    $uploadUrl = route('app.media.store-chunked', absolute: false);
    $page->script(<<<JS
        (() => {
            const { open, send } = XMLHttpRequest.prototype;
            XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                this.__url = String(url);
                return open.call(this, method, url, ...rest);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (! this.__url.includes('{$uploadUrl}')) return send.call(this, body);
                window.__editUploadFailed = true;
                Object.defineProperty(this, 'status', { value: 500 });
                Object.defineProperty(this, 'responseText', { value: 'failed' });
                setTimeout(() => this.dispatchEvent(new ProgressEvent('load')));
            };
        })();
    JS);

    $page->click("@composer-{$linkedin->id}-edit-0");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-selection\"]')?.getBoundingClientRect().width > 0");
    $page->click('@media-editor-rotate-right');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-apply\"]')?.disabled === false");
    $page->click('@media-editor-apply');
    waitForMediaEditor($page, "window.__editUploadFailed && document.querySelector('[data-testid=\"composer-{$linkedin->id}-crop-error\"]') && document.querySelector('[data-testid=\"composer-save-draft\"]')?.disabled === false");

    expect($page->script('Boolean(window.__editUploadFailed)'))->toBeTrue()
        ->and(mediaEditorTileSrc($page, "composer-{$linkedin->id}", 0))->toBe($originalSrc)
        ->and($page->script("document.querySelector('[data-testid=\"composer-save-draft\"]').disabled"))->toBeFalse();
    $page->assertVisible("@composer-{$linkedin->id}-crop-error")
        ->assertNoJavaScriptErrors();
});

/**
 * @return array{0: Post, 1: PostPlatform}
 */
function seedMediaEditorVideoPost(ContentType $contentType): array
{
    [$post, $postPlatform] = seedMediaEditorPost($contentType);
    $webm = base64_encode((string) file_get_contents(base_path('tests/fixtures/cover-3s.webm')));
    $post->update(['media' => [[
        'id' => 'video-1',
        'type' => 'video',
        'mime_type' => 'video/webm',
        'path' => 'uploads/video-1.webm',
        'url' => "data:video/webm;base64,{$webm}",
        'size' => 855,
        'meta' => ['width' => 16, 'height' => 16],
    ]]]);

    return [$post, $postPlatform];
}

test('a reel video gets a Thumbnail segment whose frame is saved as the cover offset', function () {
    [$post, $postPlatform] = seedMediaEditorVideoPost(ContentType::InstagramReel);
    $accountId = $postPlatform->social_account_id;
    $page = visit(route('app.posts.edit', $post));
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-{$accountId}-edit-0\"]')");
    $page->click("@composer-{$accountId}-edit-0");
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-cover\"]')");

    expect(mediaEditorSegmentIds($page))->toBe(['media-editor-thumbnail-tab'])
        ->and($page->script("document.querySelector('[data-testid=\"media-editor-thumbnail-tab\"]').getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script("document.querySelector('[data-testid=\"media-editor-apply\"]').disabled"))->toBeTrue();

    $page->click('@media-editor-thumbnail-tab')
        ->assertSee('Choose a thumbnail')
        ->assertVisible('@media-editor-stage-video')
        ->assertAttribute('@media-editor-thumb-0', 'aria-label', 'Video 1');
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-cover\"]')?.max === '3'");

    expect($page->script("document.querySelector('[data-testid=\"media-editor-cover\"]').max"))->toBe('3');
    $page->script(<<<'JS'
        (() => {
            const slider = document.querySelector('[data-testid="media-editor-cover"]');
            slider.value = '2.5';
            slider.dispatchEvent(new Event('input', { bubbles: true }));
        })();
    JS);
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"media-editor-apply\"]')?.disabled === false");

    waitForMediaEditor($page, "Math.abs(document.querySelector('[data-testid=\"media-editor-stage-video\"]')?.currentTime - 2.5) < 0.01");

    expect($page->script("document.querySelector('[data-testid=\"media-editor-cover-value\"]').textContent.trim()"))->toBe('0:02.5')
        ->and($page->script("document.querySelector('[data-testid=\"media-editor-cover\"]').getAttribute('aria-valuetext')"))->toBe('2.5 seconds')
        ->and($page->script("document.querySelector('[data-testid=\"media-editor-stage-video\"]').currentTime"))->toEqualWithDelta(2.5, 0.01);

    $page->click('@media-editor-apply');
    saveMediaEditorDraft($page);

    expect($post->fresh()->media[0]['meta']['cover_offset_ms'])->toBe(2500)
        ->and($post->fresh()->media[0]['id'])->toBe('video-1');
    $page->assertNoJavaScriptErrors();
});

test('a video on an X post shows no pencil', function () {
    [$post, $postPlatform] = seedMediaEditorVideoPost(ContentType::XPost);
    $accountId = $postPlatform->social_account_id;
    $page = visit(route('app.posts.edit', $post));
    waitForMediaEditor($page, "document.querySelector('[data-testid=\"composer-{$accountId}-media-item-0\"]')");

    $page->assertPresent("@composer-{$accountId}-media-item-0")
        ->assertMissing("@composer-{$accountId}-edit-0")
        ->assertNoJavaScriptErrors();
});
