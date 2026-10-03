<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

/**
 * Inject a real image into the hidden file input and dispatch `change`, driving
 * the same flow a user's file selection would. The plugin's attach() sends
 * localPaths, which Playwright rejects over its websocket connection, so a
 * DataTransfer is used instead. The poll waits for the SPA to mount first.
 */
function selectPhoto(mixed $page): void
{
    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));

    $page->script(<<<JS
        (async () => {
            const findInput = () => document.querySelector('input[type="file"]');
            for (let attempt = 0; attempt < 50 && !findInput(); attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
            const input = findInput();
            const bytes = Uint8Array.from(atob('{$base64}'), (character) => character.charCodeAt(0));
            const file = new File([bytes], 'logo.png', { type: 'image/png' });
            const data = new DataTransfer();
            data.items.add(file);
            input.files = data.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
}

/**
 * Capture the next multipart upload the page sends, keeping the uploaded File so
 * the test can decode it. The Pest browser server does not parse multipart
 * bodies (its file handling is an open TODO), so we assert the crop dispatches
 * a valid image rather than that it persists — persistence is covered by
 * ProfileUpdateTest.
 */
function recordUpload(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__uploadRequest = null;
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method, url) {
                this.__method = method;
                this.__url = url;
                return open.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function (body) {
                if (body instanceof FormData) {
                    const photo = body.get('photo');
                    window.__uploadFile = photo;
                    window.__uploadRequest = {
                        method: this.__method,
                        url: this.__url,
                        keys: [...body.keys()],
                        size: photo instanceof File ? photo.size : 0,
                        type: photo instanceof File ? photo.type : null,
                    };
                }
                return send.apply(this, arguments);
            };
        })();
    JS);
}

/**
 * Polls from the page until `$condition` holds (never sleep(): the test server
 * only ticks while Pest awaits Playwright).
 */
function waitForImageCrop(mixed $page, string $condition): void
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

function waitForCropSelection(mixed $page): void
{
    waitForImageCrop($page, "document.querySelector('[data-testid=\"media-editor-selection\"]')?.getBoundingClientRect().width > 0");
}

/**
 * The selection overlay's on-screen size.
 *
 * @return array{width: float, height: float, radius: string}
 */
function cropSelectionBox(mixed $page): array
{
    return json_decode((string) $page->script(<<<'JS'
        (() => {
            const selection = document.querySelector('[data-testid="media-editor-selection"]');
            const box = selection.getBoundingClientRect();
            return JSON.stringify({
                width: box.width,
                height: box.height,
                radius: getComputedStyle(selection).borderRadius,
            });
        })();
    JS), true);
}

/**
 * Waits for the recorded upload and decodes it, sampling the centre of each
 * quadrant of the 512px output.
 *
 * @return array<string, mixed>|null
 */
function decodedUpload(mixed $page): ?array
{
    return json_decode((string) $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 80 && !window.__uploadRequest; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
            if (!window.__uploadRequest) {
                return 'null';
            }
            const bitmap = await createImageBitmap(window.__uploadFile);
            const canvas = document.createElement('canvas');
            canvas.width = bitmap.width;
            canvas.height = bitmap.height;
            const context = canvas.getContext('2d');
            context.drawImage(bitmap, 0, 0);
            const sample = (x, y) => Array.from(context.getImageData(x, y, 1, 1).data);
            return JSON.stringify({
                ...window.__uploadRequest,
                width: bitmap.width,
                height: bitmap.height,
                pixels: {
                    topLeft: sample(128, 128),
                    topRight: sample(384, 128),
                    bottomLeft: sample(128, 384),
                    bottomRight: sample(384, 384),
                },
            });
        })();
    JS), true);
}

function isQuadrantColour(array $pixel, array $rgb): bool
{
    return abs($pixel[0] - $rgb[0]) <= 24
        && abs($pixel[1] - $rgb[1]) <= 24
        && abs($pixel[2] - $rgb[2]) <= 24
        && $pixel[3] >= 250;
}

/**
 * What the editor offers in photo mode: its tabs, crop presets and thumbnails.
 *
 * @return array{presets: int, segments: bool, altTab: bool, tagsTab: bool, thumbs: int, appearance: bool, apply: string}
 */
function photoEditorChrome(mixed $page): array
{
    return json_decode((string) $page->script(<<<'JS'
        (() => JSON.stringify({
            presets: document.querySelectorAll('[data-testid^="crop-aspect-"]').length,
            segments: Boolean(document.querySelector('[data-testid="media-editor-segments"]')),
            altTab: Boolean(document.querySelector('[data-testid="media-editor-alt-tab"]')),
            tagsTab: Boolean(document.querySelector('[data-testid="media-editor-tags-tab"]')),
            thumbs: document.querySelectorAll('[data-testid^="media-editor-thumb-"]').length,
            appearance: Boolean(document.querySelector('[data-testid="media-editor-appearance-section"]')),
            apply: document.querySelector('[data-testid="media-editor-apply"]').textContent.trim(),
        }))();
    JS), true);
}

test('a profile photo opens the media editor locked to a square 1:1 crop and saves a 512x512 avatar', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit'));

    selectPhoto($page);
    waitForCropSelection($page);
    recordUpload($page);

    $selection = cropSelectionBox($page);

    expect($selection['radius'])->toBe('0px')
        ->and($selection['width'])->toEqualWithDelta($selection['height'], 1)
        ->and(photoEditorChrome($page))->toBe([
            'presets' => 0,
            'segments' => false,
            'altTab' => false,
            'tagsTab' => false,
            'thumbs' => 0,
            'appearance' => true,
            'apply' => 'Save',
        ]);

    $page->drag('@media-editor-handle-nw', '@media-editor-stage');

    $resized = cropSelectionBox($page);

    expect($resized['width'])->toEqualWithDelta($resized['height'], 1);

    $page->click('@media-editor-reset');
    waitForImageCrop($page, "document.querySelector('[data-testid=\"media-editor-reset\"]').disabled");

    $page->click('@media-editor-apply')
        ->assertNoJavaScriptErrors();

    $request = decodedUpload($page);

    expect($request)->not->toBeNull()
        ->and($request['method'])->toBe('POST')
        ->and($request['url'])->toContain(route('app.profile.upload-photo', absolute: false))
        ->and($request['keys'])->toContain('photo')
        ->and($request['type'])->toBe('image/png')
        ->and($request['width'])->toBe(512)
        ->and($request['height'])->toBe(512)
        ->and(isQuadrantColour($request['pixels']['topLeft'], [255, 0, 0]))->toBeTrue()
        ->and(isQuadrantColour($request['pixels']['topRight'], [0, 255, 0]))->toBeTrue()
        ->and(isQuadrantColour($request['pixels']['bottomLeft'], [0, 0, 255]))->toBeTrue()
        ->and(isQuadrantColour($request['pixels']['bottomRight'], [255, 255, 0]))->toBeTrue();

    waitForImageCrop($page, "!document.querySelector('[data-testid=\"media-editor\"]')");

    expect($page->script("Boolean(document.querySelector('[data-testid=\"media-editor\"]'))"))->toBeFalse();
});

test('rotating the photo turns the uploaded avatar', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit'));

    selectPhoto($page);
    waitForCropSelection($page);
    recordUpload($page);

    $page->click('@media-editor-rotate-right')
        ->click('@media-editor-apply')
        ->assertNoJavaScriptErrors();

    $request = decodedUpload($page);

    expect($request['width'])->toBe(512)
        ->and(isQuadrantColour($request['pixels']['topLeft'], [0, 0, 255]))->toBeTrue()
        ->and(isQuadrantColour($request['pixels']['topRight'], [255, 0, 0]))->toBeTrue()
        ->and(isQuadrantColour($request['pixels']['bottomLeft'], [255, 255, 0]))->toBeTrue()
        ->and(isQuadrantColour($request['pixels']['bottomRight'], [0, 255, 0]))->toBeTrue();
});

test('cancelling the editor discards the photo without uploading', function () {
    $this->actingAs(User::factory()->create());

    $page = visit(route('app.profile.edit'));

    selectPhoto($page);
    waitForCropSelection($page);
    recordUpload($page);

    $page->click('@media-editor-cancel');
    waitForImageCrop($page, "!document.querySelector('[data-testid=\"media-editor\"]')");

    expect($page->script("Boolean(document.querySelector('[data-testid=\"media-editor\"]'))"))->toBeFalse()
        ->and($page->script('window.__uploadRequest'))->toBeNull();

    $page->assertNoJavaScriptErrors();
});

test('the workspace logo opens the same editor with a square 1:1 crop', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, membershipPivot('member'));
    $user->update(['current_workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $page = visit(route('app.workspace.settings'));

    selectPhoto($page);
    waitForCropSelection($page);
    recordUpload($page);

    $selection = cropSelectionBox($page);

    expect($selection['radius'])->toBe('0px')
        ->and($selection['width'])->toEqualWithDelta($selection['height'], 1)
        ->and(photoEditorChrome($page)['presets'])->toBe(0)
        ->and(photoEditorChrome($page)['altTab'])->toBeFalse();

    $page->click('@media-editor-apply')
        ->assertNoJavaScriptErrors();

    $request = decodedUpload($page);

    expect($request)->not->toBeNull()
        ->and($request['url'])->toContain(route('app.workspace.upload-logo', absolute: false))
        ->and($request['width'])->toBe(512)
        ->and($request['height'])->toBe(512);
});
