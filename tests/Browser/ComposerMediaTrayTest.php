<?php

declare(strict_types=1);

use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\HeicConverter;

/**
 * Poll from the page until `$condition` holds (never sleep(): the test server
 * only ticks while Pest awaits Playwright).
 */
function waitForComposerMediaTrayCondition(mixed $page, string $condition): void
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

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForComposerMediaTrayTestId(mixed $page, string $testId): void
{
    waitForComposerMediaTrayCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

function composerMediaTrayCount(mixed $page, string $testId): int
{
    return (int) $page->script("document.querySelectorAll('[data-testid=\"{$testId}\"]').length");
}

function composerMediaTrayDisabled(mixed $page, string $testId): bool
{
    return (bool) $page->script("document.querySelector('[data-testid=\"{$testId}\"]').disabled");
}

/**
 * Opens the composer for a new post with `$channels` channels of `$platform`
 * (selected only when `$select` is true, so the shared tray stays in view
 * otherwise) and wraps XMLHttpRequest so the test can count, hold, release, fail, reject
 * or watch aborts of requests to the chunked upload endpoint (`window.__uploads`).
 *
 * @return array{0: mixed, 1: Workspace, 2: SocialAccount, 3: list<SocialAccount>}
 */
function openComposerMediaTray(mixed $test, string $mode = 'pass', int $channels = 1, string|array $platform = 'linkedin', bool $select = false): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $accounts = collect(is_array($platform) ? $platform : array_fill(0, $channels, $platform))
        ->map(fn (string $network): SocialAccount => SocialAccount::factory()->{$network}()->create(['workspace_id' => $workspace->id]))
        ->all();
    $test->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerMediaTrayCondition($page, <<<'JS'
        (() => {
            const sheet = document.querySelector('[data-testid="post-composer-dialog"]');
            return sheet?.getAttribute('data-state') === 'open'
                && sheet.getAnimations().every((animation) => animation.playState !== 'running')
                && document.querySelector('[data-testid="composer-add-account"]')?.getBoundingClientRect().height > 0;
        })()
    JS);
    if ($select) {
        selectComposerMediaTrayChannels($page, $accounts);
    }
    $networks = collect($accounts)->pluck('platform')->unique()->count();
    waitForComposerMediaTrayTestId($page, $select && $networks === 1 ? "composer-{$accounts[0]->id}-dropzone" : 'composer-dropzone');

    $uploadUrl = route('app.media.store-chunked', absolute: false);
    $page->script(<<<JS
        (() => {
            const prototype = XMLHttpRequest.prototype;
            const { open, send, setRequestHeader, abort } = prototype;
            const isUpload = (xhr) => String(xhr.__url ?? '').includes('{$uploadUrl}');
            window.__uploads = { mode: '{$mode}', count: 0, held: [], aborted: 0, failNext: '{$mode}' === 'fail-first', rejectNext: null };
            prototype.open = function (method, url, ...rest) {
                this.__url = url;
                return open.call(this, method, url, ...rest);
            };
            prototype.setRequestHeader = function (name, value) {
                if (name === 'X-File-Name') this.__fileName = decodeURIComponent(value);
                return setRequestHeader.call(this, name, value);
            };
            prototype.abort = function () {
                if (isUpload(this)) {
                    window.__uploads.aborted++;
                    window.__uploads.held = window.__uploads.held.filter((held) => held.xhr !== this);
                }
                return abort.call(this);
            };
            prototype.send = function (body) {
                if (! isUpload(this)) return send.call(this, body);
                const uploads = window.__uploads;
                uploads.count++;
                if (uploads.failNext) {
                    uploads.failNext = false;
                    setTimeout(() => this.dispatchEvent(new ProgressEvent('error')));
                    return;
                }
                if (uploads.rejectNext) {
                    const message = uploads.rejectNext;
                    uploads.rejectNext = null;
                    Object.defineProperty(this, 'status', { value: 422 });
                    Object.defineProperty(this, 'responseText', { value: JSON.stringify({ message, errors: { file_name: [message] } }) });
                    setTimeout(() => this.dispatchEvent(new ProgressEvent('load')));
                    return;
                }
                if (uploads.mode !== 'hold') return send.call(this, body);
                uploads.held.push({ name: this.__fileName, xhr: this, release: () => send.call(this, body) });
            };
            window.__releaseUpload = (name) => {
                const index = window.__uploads.held.findIndex((held) => held.name === name);
                window.__uploads.held.splice(index, 1)[0].release();
            };
        })();
    JS);

    return [$page, $workspace, $accounts[0], $accounts];
}

/**
 * @param  list<SocialAccount>  $accounts
 */
function selectComposerMediaTrayChannels(mixed $page, array $accounts): void
{
    $page->click('@composer-add-account');
    foreach ($accounts as $account) {
        $page->click("@composer-account-option-{$account->id}");
    }
}

/**
 * Puts files on the hidden input and fires `change`, the way the OS dialog
 * would. `$files` is a JS array expression of `File` objects.
 */
function selectComposerMediaTrayFiles(mixed $page, string $files, string $prefix = 'composer'): void
{
    $page->script(<<<JS
        (() => {
            const base64 = (value) => Uint8Array.from(atob(value), (character) => character.charCodeAt(0));
            const input = document.querySelector('[data-testid="{$prefix}-file-input"]');
            const transfer = new DataTransfer();
            for (const file of {$files}) transfer.items.add(file);
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
}

function composerMediaTrayPng(string $name): string
{
    $base64 = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));

    return "new File([base64('{$base64}')], '{$name}', { type: 'image/png' })";
}

test('the dropzone opens the file dialog, not the library picker', function () {
    [$page] = openComposerMediaTray($this);

    $page->script(<<<'JS'
        (() => {
            window.__fileDialogOpened = 0;
            document.querySelector('[data-testid="composer-file-input"]').click = () => { window.__fileDialogOpened++; };
        })();
    JS);
    $page->click('@composer-dropzone');

    expect($page->script('window.__fileDialogOpened'))->toBe(1);
    $page->assertMissing('@media-picker-confirm')
        ->assertSee('select a file')
        ->assertNoJavaScriptErrors();
});

test('the toolbar has no plus button and the channel dropzone opens its own file dialog', function () {
    [$page, , $account] = openComposerMediaTray($this);

    $page->assertPresent('@composer-base-toolbar')
        ->assertNotPresent('@composer-base-add-media');

    selectComposerMediaTrayChannels($page, [$account]);
    waitForComposerMediaTrayTestId($page, "composer-{$account->id}-dropzone");
    $page->script(<<<JS
        (() => {
            window.__fileDialogOpened = 0;
            document.querySelector('[data-testid="composer-{$account->id}-file-input"]').click = () => { window.__fileDialogOpened++; };
        })();
    JS);
    $page->assertNotPresent("@composer-{$account->id}-add-media")
        ->click("@composer-{$account->id}-dropzone");

    expect($page->script('window.__fileDialogOpened'))->toBe(1);
    $page->assertMissing('@media-picker-confirm')
        ->assertNoJavaScriptErrors();
});

test('each file gets an uploading tile and becomes a ready tile in the order it was added', function () {
    [$page] = openComposerMediaTray($this, 'hold');

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('first.png').', '.composerMediaTrayPng('second.png').']');
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 2');
    expect(composerMediaTrayCount($page, 'composer-upload-item'))->toBe(2)
        ->and(composerMediaTrayCount($page, 'composer-media-item'))->toBe(0);

    $page->script("window.__releaseUpload('second.png')");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-upload-item\"]')[1]?.querySelector('[role=\"progressbar\"]')?.getAttribute('aria-valuenow') === '100'");
    expect(composerMediaTrayCount($page, 'composer-media-item'))->toBe(0)
        ->and(composerMediaTrayCount($page, 'composer-upload-item'))->toBe(2);

    $page->script("window.__releaseUpload('first.png')");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === 2");

    $sources = $page->script("[...document.querySelectorAll('[data-testid=\"composer-media-item\"] img')].map((image) => image.getAttribute('src'))");
    $uploads = Media::where('collection', Media::COLLECTION_UPLOADS)->get()->keyBy('original_filename');
    expect($sources)->toBe([$uploads['first.png']->url, $uploads['second.png']->url])
        ->and(composerMediaTrayCount($page, 'composer-upload-item'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('no more than three files upload at once', function () {
    [$page] = openComposerMediaTray($this, 'hold');

    selectComposerMediaTrayFiles($page, '['.implode(', ', array_map(composerMediaTrayPng(...), ['a.png', 'b.png', 'c.png', 'd.png'])).']');
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 3');
    expect($page->script('window.__uploads.held.map((held) => held.name)'))->toBe(['a.png', 'b.png', 'c.png'])
        ->and(composerMediaTrayCount($page, 'composer-upload-item'))->toBe(4);

    $page->script("window.__releaseUpload('a.png')");
    waitForComposerMediaTrayCondition($page, "window.__uploads.held.some((held) => held.name === 'd.png')");
    expect($page->script('window.__uploads.held.map((held) => held.name)'))->toBe(['b.png', 'c.png', 'd.png']);
    $page->assertNoJavaScriptErrors();
});

test('a file over the image cap becomes an error tile and is never sent', function () {
    [$page] = openComposerMediaTray($this);
    $bytes = MediaType::Image->maxSizeInBytes() + 1;

    selectComposerMediaTrayFiles($page, "[new File([new Uint8Array({$bytes})], 'huge.png', { type: 'image/png' })]");
    waitForComposerMediaTrayTestId($page, 'composer-upload-error');

    expect($page->script('document.querySelector(\'[data-testid="composer-upload-error"]\').dataset.reason'))->toBe('too_large')
        ->and($page->script('window.__uploads.count'))->toBe(0)
        ->and(Media::count())->toBe(0);
    $page->assertSeeIn('@composer-upload-error', 'huge.png')
        ->assertSeeIn('@composer-upload-error', 'The limit is '.MediaType::Image->maxSizeInMb().' MB')
        ->assertMissing('@composer-upload-retry-0')
        ->click('@composer-upload-remove-0')
        ->assertMissing('@composer-upload-error')
        ->assertNoJavaScriptErrors();
});

test('an unsupported file type becomes an error tile and is never sent', function () {
    [$page] = openComposerMediaTray($this);

    selectComposerMediaTrayFiles($page, "[new File(['hello'], 'notes.txt', { type: 'text/plain' })]");
    waitForComposerMediaTrayTestId($page, 'composer-upload-error');

    expect($page->script('document.querySelector(\'[data-testid="composer-upload-error"]\').dataset.reason'))->toBe('unsupported_type')
        ->and($page->script('window.__uploads.count'))->toBe(0);
    $page->assertSeeIn('@composer-upload-error', "This file type isn't supported")
        ->assertMissing('@composer-upload-retry-0')
        ->assertNoJavaScriptErrors();
});

test('saving is blocked while an upload is in flight and the finished upload is adopted by the save', function () {
    [$page, $workspace, $account] = openComposerMediaTray($this, 'hold');
    $page->fill('@composer-base-content', 'Post with an upload');

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('in-flight.png').']');
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 1');
    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeTrue();
    selectComposerMediaTrayChannels($page, [$account]);
    waitForComposerMediaTrayTestId($page, 'composer-submit');
    expect(composerMediaTrayDisabled($page, 'composer-submit'))->toBeTrue()
        ->and(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeTrue();

    $page->script("window.__releaseUpload('in-flight.png')");
    waitForComposerMediaTrayCondition($page, "document.querySelector('[data-testid=\"composer-save-draft\"]')?.disabled === false");
    expect(composerMediaTrayDisabled($page, 'composer-submit'))->toBeFalse();
    $page->click('@composer-save-draft');
    waitForComposerMediaTrayCondition($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')");

    $post = Post::where('workspace_id', $workspace->id)->sole();
    $owned = $post->ownedMedia()->sole();
    expect($owned->original_filename)->toBe('in-flight.png')
        ->and($owned->collection)->toBe(Media::COLLECTION_MEDIA)
        ->and($owned->upload_token)->toBeNull()
        ->and($post->media[0]['id'])->toBe($owned->id);
    $page->assertNoJavaScriptErrors();
});

test('cancel removes an uploading tile and retry recovers a network failure', function () {
    [$page] = openComposerMediaTray($this, 'hold', platform: ['instagram', 'threads'], select: true);

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('cancel-me.png').']');
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 1');
    $page->click('@composer-upload-cancel-0')
        ->assertMissing('@composer-upload-item')
        ->assertMissing('@composer-media-item');
    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeFalse();

    $page->script("window.__uploads.mode = 'pass'; window.__uploads.failNext = true;");
    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('retry-me.png').']');
    waitForComposerMediaTrayTestId($page, 'composer-upload-error');
    expect($page->script('document.querySelector(\'[data-testid="composer-upload-error"]\').dataset.reason'))->toBe('network');

    $page->click('@composer-upload-retry-0');
    waitForComposerMediaTrayTestId($page, 'composer-media-item');
    $page->assertMissing('@composer-upload-error')
        ->assertNoJavaScriptErrors();
    expect(Media::where('collection', Media::COLLECTION_UPLOADS)->pluck('original_filename')->all())->toBe(['retry-me.png']);
});

test('a HEIC photo shows the heic_unavailable error tile and is never sent while the server cannot convert it', function () {
    config(['trypost.media.heic_conversion' => false]);
    HeicConverter::flush();
    [$page] = openComposerMediaTray($this);

    expect($page->script('document.querySelector(\'[data-testid="composer-file-input"]\').accept'))->not->toContain('heic');

    selectComposerMediaTrayFiles($page, "[new File(['heic-bytes'], 'IMG_0001.HEIC', { type: 'image/heic' })]");
    waitForComposerMediaTrayTestId($page, 'composer-upload-error');

    expect($page->script('document.querySelector(\'[data-testid="composer-upload-error"]\').dataset.reason'))->toBe('heic_unavailable')
        ->and($page->script('window.__uploads.count'))->toBe(0);
    $page->assertSeeIn('@composer-upload-error', 'IMG_0001.HEIC')
        ->assertMissing('@composer-upload-retry-0')
        ->assertNoJavaScriptErrors();
});

test('a rejected upload shows the server message without Retry', function () {
    [$page] = openComposerMediaTray($this);

    $page->script("window.__uploads.rejectNext = 'This HEIC file could not be read.'");
    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('rejected.png').']');
    waitForComposerMediaTrayTestId($page, 'composer-upload-error');

    expect($page->script('document.querySelector(\'[data-testid="composer-upload-error"]\').dataset.reason'))->toBe('rejected');
    $page->assertSeeIn('@composer-upload-error', 'This HEIC file could not be read.')
        ->assertMissing('@composer-upload-retry-0')
        ->assertNoJavaScriptErrors();
});

test('a failed tile blocks saving until it is retried or removed', function () {
    [$page] = openComposerMediaTray($this, 'fail-first', platform: ['instagram', 'threads'], select: true);
    $page->fill('@composer-base-content', 'Blocked by a failed upload');

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('failed.png').']');
    waitForComposerMediaTrayTestId($page, 'composer-upload-error');

    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeTrue();
    $page->assertVisible('@composer-upload-blocked')
        ->click('@composer-upload-remove-0')
        ->assertMissing('@composer-upload-blocked');
    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('three uploads run at once across the shared and channel trays, and uploads survive step and channel changes', function () {
    [$page, , $first, $accounts] = openComposerMediaTray($this, 'hold', platform: ['instagram', 'threads'], select: true);
    $second = $accounts[1];

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('shared-a.png').', '.composerMediaTrayPng('shared-b.png').']');
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 2');
    $page->click('@composer-next');
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-dropzone");

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('channel-c.png').', '.composerMediaTrayPng('channel-d.png').']', "composer-{$first->id}");
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 3');
    expect($page->script('window.__uploads.held.map((held) => held.name)'))->toBe(['shared-a.png', 'shared-b.png', 'channel-c.png'])
        ->and(composerMediaTrayCount($page, "composer-{$first->id}-upload-item"))->toBe(4);

    $page->click("@composer-account-{$second->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$second->id}-dropzone");
    $page->script("window.__releaseUpload('shared-a.png'); window.__releaseUpload('shared-b.png');");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-{$second->id}-media-item\"]').length === 2 && window.__uploads.held.length === 2");
    $page->script("window.__releaseUpload('channel-c.png'); window.__releaseUpload('channel-d.png');");
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 0');

    $page->click("@composer-account-{$first->id}");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-{$first->id}-media-item\"]').length === 4");
    expect(composerMediaTrayCount($page, "composer-{$first->id}-media-item"))->toBe(4)
        ->and(composerMediaTrayCount($page, "composer-{$second->id}-media-item"))->toBe(0);

    $page->click('@composer-back');
    waitForComposerMediaTrayTestId($page, 'composer-back-confirm-go');
    $page->click('@composer-back-confirm-go');
    waitForComposerMediaTrayTestId($page, 'composer-media-item');
    expect(composerMediaTrayCount($page, 'composer-media-item'))->toBe(2)
        ->and(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('closing the composer aborts uploads still in flight', function () {
    [$page] = openComposerMediaTray($this, 'hold');

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('left-behind.png').']');
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 1');
    $page->click('@composer-close');
    waitForComposerMediaTrayCondition($page, '!document.querySelector(\'[data-testid="post-composer-dialog"]\')');

    expect($page->script('window.__uploads.aborted'))->toBe(1)
        ->and(Media::count())->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('an expired upload is reported on its tile in the shared tray and in a channel tray', function () {
    [$page, , $account] = openComposerMediaTray($this, platform: ['instagram', 'threads'], select: true);
    $page->fill('@composer-base-content', 'Expired upload');

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('shared-expired.png').']');
    waitForComposerMediaTrayTestId($page, 'composer-media-item');
    Media::where('original_filename', 'shared-expired.png')->delete();
    $page->click('@composer-save-draft');
    waitForComposerMediaTrayTestId($page, 'composer-media-error-0');
    $page->assertSeeIn('@composer-media-error-0', 'This upload expired. Add the file again.')
        ->click('@composer-remove-0')
        ->assertMissing('@composer-media-error-0');

    $page->click('@composer-next');
    waitForComposerMediaTrayTestId($page, "composer-{$account->id}-dropzone");
    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('channel-expired.png').']', "composer-{$account->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$account->id}-media-item");
    Media::where('original_filename', 'channel-expired.png')->delete();
    $page->click('@composer-save-draft');
    waitForComposerMediaTrayTestId($page, "composer-{$account->id}-media-error-0");
    $page->assertSeeIn("@composer-{$account->id}-media-error-0", 'This upload expired. Add the file again.')
        ->assertNoJavaScriptErrors();
    expect(Post::count())->toBe(0);
});

/**
 * Uploads `$names` into the shared tray and waits until each is a ready tile.
 *
 * @param  list<string>  $names
 */
function uploadComposerMediaTrayTiles(mixed $page, array $names): void
{
    selectComposerMediaTrayFiles($page, '['.implode(', ', array_map(composerMediaTrayPng(...), $names)).']');
    $count = count($names);
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === {$count}");
}

/**
 * @return list<string>
 */
function composerMediaTrayOrder(mixed $page): array
{
    $sources = $page->script("[...document.querySelectorAll('[data-testid=\"composer-media-item\"] img')].map((image) => image.getAttribute('src'))");
    $names = Media::where('collection', Media::COLLECTION_UPLOADS)->get()->mapWithKeys(fn (Media $media): array => [$media->url => $media->original_filename]);

    return array_map(fn (string $source): string => $names[$source], $sources);
}

test('dragging a tile before another reorders the tray and the save keeps the order', function () {
    [$page, $workspace, $account] = openComposerMediaTray($this);
    $page->fill('@composer-base-content', 'Reordered media');
    uploadComposerMediaTrayTiles($page, ['first.png', 'second.png']);
    waitForComposerMediaTrayTestId($page, 'composer-media-item-1');

    $second = $page->script("document.querySelectorAll('[data-testid=\"composer-media-item\"] img')[1].getAttribute('src')");

    $page->drag('@composer-drag-handle-1', '@composer-media-item-0');
    waitForComposerMediaTrayCondition($page, "document.querySelector('[data-testid=\"composer-media-item\"] img')?.getAttribute('src') === '{$second}'");
    expect(composerMediaTrayOrder($page))->toBe(['second.png', 'first.png']);

    selectComposerMediaTrayChannels($page, [$account]);
    $page->click('@composer-save-draft');
    waitForComposerMediaTrayCondition($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')");

    $post = Post::where('workspace_id', $workspace->id)->sole();
    $owned = $post->ownedMedia()->get()->keyBy('id');
    expect(array_map(fn (array $item): string => $owned[$item['id']]->original_filename, $post->media))->toBe(['second.png', 'first.png']);
    $page->assertNoJavaScriptErrors();
});

test('arrow keys on the drag handle move the tile, keep focus on its handle and announce the move', function () {
    [$page] = openComposerMediaTray($this);
    uploadComposerMediaTrayTiles($page, ['first.png', 'second.png']);

    $page->keys('@composer-drag-handle-0', 'ArrowRight');
    waitForComposerMediaTrayCondition($page, "document.activeElement?.dataset.testid === 'composer-drag-handle-1'");

    expect(composerMediaTrayOrder($page))->toBe(['second.png', 'first.png'])
        ->and($page->script('document.activeElement?.dataset.testid'))->toBe('composer-drag-handle-1')
        ->and($page->script("document.querySelector('[data-testid=\"composer-media-tray\"] [data-testid=\"composer-media-moved\"]').textContent.trim()"))
        ->toBe('Moved first.png to position 2 of 2');

    $page->keys('@composer-drag-handle-1', 'ArrowRight');
    expect(composerMediaTrayOrder($page))->toBe(['second.png', 'first.png'])
        ->and($page->script('document.activeElement?.dataset.testid'))->toBe('composer-drag-handle-1');

    $page->keys('@composer-drag-handle-1', 'ArrowLeft');
    waitForComposerMediaTrayCondition($page, "document.activeElement?.dataset.testid === 'composer-drag-handle-0'");
    expect(composerMediaTrayOrder($page))->toBe(['first.png', 'second.png']);
    $page->assertNoJavaScriptErrors();
});

test('in a right-to-left layout ArrowLeft moves the tile towards the end', function () {
    [$page] = openComposerMediaTray($this);
    uploadComposerMediaTrayTiles($page, ['first.png', 'second.png']);
    $page->script("document.documentElement.dir = 'rtl'");

    $page->keys('@composer-drag-handle-0', 'ArrowLeft');
    waitForComposerMediaTrayCondition($page, "document.activeElement?.dataset.testid === 'composer-drag-handle-1'");

    expect(composerMediaTrayOrder($page))->toBe(['second.png', 'first.png']);
    $page->assertNoJavaScriptErrors();
});

test('a tile is dragged only by its handle and a single tile has no handle', function () {
    [$page] = openComposerMediaTray($this);
    uploadComposerMediaTrayTiles($page, ['only.png']);

    expect($page->script("getComputedStyle(document.querySelector('[data-testid=\"composer-drag-handle-0\"]')).display"))->toBe('none');

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('second.png').']');
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === 2");
    $page->script(<<<'JS'
        (() => {
            const source = document.querySelector('[data-testid="composer-media-item-1"]');
            const box = source.getBoundingClientRect();
            source.dispatchEvent(new DragEvent('dragstart', { clientX: box.left + box.width / 2, clientY: box.bottom - 10, bubbles: true, cancelable: true, dataTransfer: new DataTransfer() }));
        })();
    JS);

    expect($page->script("document.querySelector('[data-testid=\"composer-media-item-1\"]').hasAttribute('data-dragging')"))->toBeFalse()
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"composer-drag-handle-0\"]')).display"))->not->toBe('none');
    $page->assertNoJavaScriptErrors();
});

/**
 * Dispatches a `paste` on `$testId` carrying `$files` (a JS array expression of
 * `File` objects) and optional plain text, and returns whether the composer
 * prevented the browser's default paste.
 */
function pasteIntoComposerMediaTray(mixed $page, string $testId, string $files, string $text = ''): bool
{
    return (bool) $page->script(<<<JS
        (() => {
            const base64 = (value) => Uint8Array.from(atob(value), (character) => character.charCodeAt(0));
            const transfer = new DataTransfer();
            for (const file of {$files}) transfer.items.add(file);
            if ('{$text}') transfer.setData('text/plain', '{$text}');
            const target = document.querySelector('[data-testid="{$testId}"]');
            return !target.dispatchEvent(new ClipboardEvent('paste', { clipboardData: transfer, bubbles: true, cancelable: true }));
        })()
    JS);
}

test('pasting an image into the post content adds an upload and leaves text paste alone', function () {
    [$page] = openComposerMediaTray($this);

    expect(pasteIntoComposerMediaTray($page, 'composer-base-content', '['.composerMediaTrayPng('pasted.png').']'))->toBeTrue();
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === 1");

    expect(pasteIntoComposerMediaTray($page, 'composer-base-content', '[]', 'hello'))->toBeFalse()
        ->and(composerMediaTrayCount($page, 'composer-media-item'))->toBe(1)
        ->and(Media::where('collection', Media::COLLECTION_UPLOADS)->sole()->original_filename)->toBe('pasted.png');
    $page->assertNoJavaScriptErrors();
});

test('a pasted file the tray does not accept becomes an error tile and is never sent', function () {
    [$page] = openComposerMediaTray($this);

    expect(pasteIntoComposerMediaTray($page, 'composer-base-content', "[new File(['MZ'], 'setup.exe', { type: 'application/x-msdownload' })]"))->toBeTrue();
    waitForComposerMediaTrayTestId($page, 'composer-upload-error');

    expect($page->script('document.querySelector(\'[data-testid="composer-upload-error"]\').dataset.reason'))->toBe('unsupported_type')
        ->and($page->script('window.__uploads.count'))->toBe(0);
    $page->assertSeeIn('@composer-upload-error', 'setup.exe')
        ->assertNoJavaScriptErrors();
});

test('a clipboard carrying text and an image pastes the text and adds no upload', function () {
    [$page] = openComposerMediaTray($this);

    expect(pasteIntoComposerMediaTray($page, 'composer-base-content', '['.composerMediaTrayPng('cells.png').']', 'A1 B1'))->toBeFalse()
        ->and(composerMediaTrayCount($page, 'composer-upload-item'))->toBe(0)
        ->and(composerMediaTrayCount($page, 'composer-media-item'))->toBe(0)
        ->and($page->script('window.__uploads.count'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('pasting an image into a channel caption adds it to that channel tray only', function () {
    [$page, , $account] = openComposerMediaTray($this, platform: ['instagram', 'threads'], select: true);
    $page->fill('@composer-base-content', 'Shared text');
    $page->click('@composer-next');
    waitForComposerMediaTrayTestId($page, "composer-caption-{$account->id}");

    expect(pasteIntoComposerMediaTray($page, "composer-caption-{$account->id}", '['.composerMediaTrayPng('channel.png').']'))->toBeTrue();
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-{$account->id}-media-item\"]').length === 1");

    expect(composerMediaTrayCount($page, "composer-{$account->id}-media-item"))->toBe(1);
    $page->click('@composer-back');
    waitForComposerMediaTrayTestId($page, 'composer-back-confirm-go');
    $page->click('@composer-back-confirm-go');
    waitForComposerMediaTrayTestId($page, 'composer-dropzone');
    expect(composerMediaTrayCount($page, 'composer-media-item'))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('in a right-to-left layout dropping a tile on the start edge of another puts it before that tile', function () {
    [$page] = openComposerMediaTray($this);
    uploadComposerMediaTrayTiles($page, ['first.png', 'second.png']);
    $page->script("document.documentElement.dir = 'rtl'");
    $second = $page->script("document.querySelectorAll('[data-testid=\"composer-media-item\"] img')[1].getAttribute('src')");

    $page->script(<<<'JS'
        (() => {
            const source = document.querySelector('[data-testid="composer-media-item-1"]');
            const target = document.querySelector('[data-testid="composer-media-item-0"]');
            const handle = document.querySelector('[data-testid="composer-drag-handle-1"]').getBoundingClientRect();
            const box = target.getBoundingClientRect();
            const point = { clientX: box.right - 4, clientY: box.top + box.height / 2 };
            window.__trayDrag = { source, target, point, dataTransfer: new DataTransfer() };
            const fire = (element, type, at = point) => element.dispatchEvent(new DragEvent(type, { ...at, bubbles: true, cancelable: true, dataTransfer: window.__trayDrag.dataTransfer }));
            window.__trayDrag.fire = fire;
            fire(source, 'dragstart', { clientX: handle.left + handle.width / 2, clientY: handle.top + handle.height / 2 });
        })();
    JS);
    waitForComposerMediaTrayCondition($page, "document.querySelector('[data-testid=\"composer-media-item-1\"]')?.hasAttribute('data-dragging')");
    $page->script(<<<'JS'
        (() => {
            const { fire, target } = window.__trayDrag;
            fire(target, 'dragenter');
            fire(target, 'dragover');
        })();
    JS);
    waitForComposerMediaTrayCondition($page, "document.querySelector('[data-testid=\"composer-drop-indicator\"]')?.dataset.edge === 'start'");
    expect($page->script("document.querySelector('[data-testid=\"composer-drop-indicator\"]')?.dataset.edge"))->toBe('start');
    $page->script(<<<'JS'
        (() => {
            const { fire, source, target } = window.__trayDrag;
            fire(target, 'drop');
            fire(source, 'dragend');
        })();
    JS);
    waitForComposerMediaTrayCondition($page, "document.querySelector('[data-testid=\"composer-media-item\"] img')?.getAttribute('src') === '{$second}'");

    expect(composerMediaTrayOrder($page))->toBe(['second.png', 'first.png']);
    $page->assertNoJavaScriptErrors();
});

test('deselecting a channel aborts its uploads, unblocks saving and leaves nothing behind when it is re-selected', function () {
    [$page, , $first, $accounts] = openComposerMediaTray($this, 'hold', platform: ['instagram', 'threads'], select: true);
    $second = $accounts[1];
    $page->fill('@composer-base-content', 'Deselect mid-upload');
    $page->click('@composer-next');
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-dropzone");

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('held-channel.png').", new File(['x'], 'notes.txt', { type: 'text/plain' })]", "composer-{$first->id}");
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 1');
    expect(composerMediaTrayCount($page, "composer-{$first->id}-upload-error"))->toBe(1)
        ->and(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeTrue();

    $page->click("@composer-remove-account-{$first->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$second->id}-dropzone");
    expect($page->script('window.__uploads.aborted'))->toBe(1)
        ->and(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeFalse();
    $page->assertMissing('@composer-upload-blocked');

    $page->click('@composer-add-account')->click("@composer-account-option-{$first->id}");
    $page->click('@composer-next');
    $page->click("@composer-account-{$first->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-dropzone");
    expect(composerMediaTrayCount($page, "composer-{$first->id}-upload-error"))->toBe(0)
        ->and(composerMediaTrayCount($page, "composer-{$first->id}-upload-item"))->toBe(0)
        ->and(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeFalse()
        ->and(Media::count())->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('a server error follows the expired item when an earlier item is removed', function () {
    [$page] = openComposerMediaTray($this, platform: ['instagram', 'threads'], select: true);
    $page->fill('@composer-base-content', 'Two uploads, one expired');

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('kept.png').', '.composerMediaTrayPng('expired.png').']');
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === 2");
    $expired = Media::where('original_filename', 'expired.png')->sole();
    $expiredUrl = $expired->url;
    $expired->delete();

    $page->click('@composer-save-draft');
    waitForComposerMediaTrayTestId($page, 'composer-media-error-1');
    $page->assertMissing('@composer-media-error-0');

    $page->click('@composer-remove-0');
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === 1");
    waitForComposerMediaTrayTestId($page, 'composer-media-error-0');
    expect($page->script("document.querySelector('[data-testid=\"composer-media-item\"] img').getAttribute('src')"))->toBe($expiredUrl);
    $page->assertSeeIn('@composer-media-error-0', 'This upload expired. Add the file again.')
        ->assertMissing('@composer-media-error-1')
        ->assertNoJavaScriptErrors();
});

/**
 * Selects one Instagram feed channel, uploads an image and removes it, which
 * leaves the warning bar and one suggestion behind.
 *
 * @return array{0: mixed, 1: SocialAccount}
 */
function removeComposerMediaTrayInstagramImage(mixed $test): array
{
    [$page, , $account] = openComposerMediaTray($test, platform: 'instagram', select: true);
    waitForComposerMediaTrayTestId($page, "composer-media-warning-{$account->id}");

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('beach.png').']', "composer-{$account->id}");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-{$account->id}-media-item\"]').length === 1");
    $page->assertMissing("@composer-media-warning-{$account->id}")
        ->assertMissing("@composer-{$account->id}-suggested-media")
        ->click("@composer-{$account->id}-remove-0");
    waitForComposerMediaTrayTestId($page, "composer-{$account->id}-suggested-0");

    return [$page, $account];
}

test('removing the only image of an Instagram feed post warns inline and suggests it back', function () {
    [$page, $account] = removeComposerMediaTrayInstagramImage($this);

    $page->assertSeeIn("@composer-media-warning-{$account->id}", 'Please include an image or video.')
        ->assertSeeIn("@composer-{$account->id}-suggested-media", 'Suggested media');
    $removedSource = $page->script("document.querySelector('[data-testid=\"composer-{$account->id}-suggested-0\"] img')?.getAttribute('src')");
    expect($removedSource)->toBe(Media::where('collection', Media::COLLECTION_UPLOADS)->sole()->url)
        ->and(composerMediaTrayCount($page, "composer-{$account->id}-media-item"))->toBe(0);

    $page->click("@composer-{$account->id}-suggested-0");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-{$account->id}-media-item\"]').length === 1");

    expect($page->script("document.querySelector('[data-testid=\"composer-{$account->id}-media-item\"] img')?.getAttribute('src')"))->toBe($removedSource);
    $page->assertMissing("@composer-media-warning-{$account->id}")
        ->assertMissing("@composer-{$account->id}-suggested-media")
        ->assertNoJavaScriptErrors();
});

test('dismissing suggested media hides the panel until the next removal', function () {
    [$page, $account] = removeComposerMediaTrayInstagramImage($this);

    $page->click("@composer-{$account->id}-suggested-dismiss")
        ->assertMissing("@composer-{$account->id}-suggested-media")
        ->assertMissing("@composer-{$account->id}-suggested-0")
        ->assertVisible("@composer-media-warning-{$account->id}");

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('pier.png').']', "composer-{$account->id}");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-{$account->id}-media-item\"]').length === 1");
    $page->click("@composer-{$account->id}-remove-0");
    waitForComposerMediaTrayTestId($page, "composer-{$account->id}-suggested-0");

    expect(composerMediaTrayCount($page, "composer-{$account->id}-suggested-1"))->toBe(0);
    $page->assertNoJavaScriptErrors();
});

test('suggested media survives step changes and channel switches', function () {
    [$page, , $first, $accounts] = openComposerMediaTray($this, platform: ['instagram', 'threads'], select: true);
    $second = $accounts[1];

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('shared.png').']');
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-media-item\"]').length === 1");
    $page->click('@composer-remove-0');
    waitForComposerMediaTrayTestId($page, 'composer-suggested-0');

    $page->click('@composer-next');
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-dropzone");
    $page->assertMissing("@composer-{$first->id}-suggested-0");

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('channel.png').']', "composer-{$first->id}");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-{$first->id}-media-item\"]').length === 1");
    $page->click("@composer-{$first->id}-remove-0");
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-suggested-0");

    $page->click("@composer-account-{$second->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$second->id}-dropzone");
    $page->assertMissing("@composer-{$second->id}-suggested-0");

    $page->click("@composer-account-{$first->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-suggested-0");
    $page->assertVisible("@composer-{$first->id}-suggested-0");

    $page->click('@composer-back');
    waitForComposerMediaTrayTestId($page, 'composer-back-confirm-go');
    $page->click('@composer-back-confirm-go');
    waitForComposerMediaTrayTestId($page, 'composer-suggested-0');
    $page->assertVisible('@composer-suggested-0')
        ->assertNoJavaScriptErrors();
});

/**
 * Opens one Instagram feed channel without media, so the primary
 * button is blocked by the requires-media issue.
 *
 * @return array{0: mixed, 1: SocialAccount}
 */
function openComposerMediaTrayBlockedSubmit(mixed $test): array
{
    [$page, , $account] = openComposerMediaTray($test, platform: 'instagram', select: true);

    waitForComposerMediaTrayTestId($page, "composer-media-warning-{$account->id}");
    expect($page->script("document.querySelector('[data-testid=\"composer-submit\"]').getAttribute('aria-disabled')"))->toBe('true');
    $page->assertDisabled('@composer-submit');

    return [$page, $account];
}

test('hovering the blocked primary button names the first issue and the channel', function () {
    [$page, $account] = openComposerMediaTrayBlockedSubmit($this);

    $page->hover('@composer-submit');
    waitForComposerMediaTrayTestId($page, 'composer-blocked-tooltip');

    $page->assertSeeIn('@composer-blocked-tooltip', "Please include an image or video. ({$account->display_name})")
        ->assertNoJavaScriptErrors();
});

test('focusing the blocked primary button with the keyboard shows the same tooltip', function () {
    [$page, $account] = openComposerMediaTrayBlockedSubmit($this);

    $page->script("document.querySelector('[data-testid=\"composer-submit\"]').focus()");
    waitForComposerMediaTrayTestId($page, 'composer-blocked-tooltip');

    $page->assertSeeIn('@composer-blocked-tooltip', "Please include an image or video. ({$account->display_name})");
    expect($page->script("document.activeElement?.getAttribute('data-testid')"))->toBe('composer-submit')
        ->and($page->script("document.getElementById(document.querySelector('[data-testid=\"composer-submit\"]').getAttribute('aria-describedby'))?.textContent?.trim()"))
        ->toContain('Please include an image or video.');
    $page->assertNoJavaScriptErrors();
});

test('a failed upload in a network card stops blocking once only one network is left', function () {
    [$page, , $first, $accounts] = openComposerMediaTray($this, 'fail-first', platform: ['instagram', 'threads'], select: true);

    $page->click('@composer-next');
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-dropzone");
    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('broken.png').']', "composer-{$first->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-upload-error");
    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeTrue();

    $page->click("@composer-remove-account-{$accounts[1]->id}");
    waitForComposerMediaTrayCondition($page, "!document.querySelector('[data-testid=\"composer-account-{$accounts[1]->id}\"]')");

    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeFalse();
    $page->assertMissing("@composer-{$first->id}-upload-error")
        ->assertNoJavaScriptErrors();
});

test('an upload still running when customizing shows in every card and lands in a network added afterwards', function () {
    [$page, , $first, $accounts] = openComposerMediaTray($this, 'hold', platform: ['instagram', 'threads', 'linkedin']);
    [, $second, $linkedin] = $accounts;
    selectComposerMediaTrayChannels($page, [$first, $second]);
    waitForComposerMediaTrayTestId($page, 'composer-next');

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('in-flight.png').']');
    waitForComposerMediaTrayCondition($page, 'window.__uploads.held.length === 1');
    $page->click('@composer-next');
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-upload-item");
    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeTrue();

    $page->click("@composer-account-{$second->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$second->id}-upload-item");

    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$linkedin->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$linkedin->id}-upload-item");
    $page->script("window.__releaseUpload('in-flight.png');");
    waitForComposerMediaTrayCondition($page, "document.querySelectorAll('[data-testid=\"composer-{$linkedin->id}-media-item\"]').length === 1");

    $page->assertMissing("@composer-{$linkedin->id}-upload-item")
        ->click("@composer-account-{$first->id}");
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-media-item");
    expect(composerMediaTrayCount($page, "composer-{$first->id}-media-item"))->toBe(1)
        ->and(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

test('an upload that failed before customizing shows in the cards and removing it unblocks saving', function () {
    [$page, , $first] = openComposerMediaTray($this, 'fail-first', platform: ['instagram', 'threads'], select: true);

    selectComposerMediaTrayFiles($page, '['.composerMediaTrayPng('failed-early.png').']');
    waitForComposerMediaTrayTestId($page, 'composer-upload-error');
    $page->click('@composer-next');
    waitForComposerMediaTrayTestId($page, "composer-{$first->id}-upload-error");
    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeTrue();

    $page->click("@composer-{$first->id}-upload-remove-0");
    waitForComposerMediaTrayCondition($page, "!document.querySelector('[data-testid=\"composer-{$first->id}-upload-error\"]')");

    expect(composerMediaTrayDisabled($page, 'composer-save-draft'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});
