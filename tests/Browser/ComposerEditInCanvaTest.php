<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MediaImportStatus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function waitForEditInCanvaCondition(mixed $page, string $condition, int $attempts = 100): void
{
    $page->script(<<<JS
        (async () => {
            for (let attempt = 0; attempt < {$attempts}; attempt++) {
                if ({$condition}) return;
                await new Promise((resolve) => setTimeout(resolve, 50));
            }
        })();
    JS);
}

function waitForEditInCanvaTestId(mixed $page, string $testId): void
{
    waitForEditInCanvaCondition($page, "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0");
}

/**
 * A finished Canva import: a temporary upload that remembers its design.
 *
 * @return array{import: string, media: Media}
 */
function editInCanvaBrowserImport(User $user, Workspace $workspace, string $designId, ?string $replaces = null, array $edits = []): array
{
    $media = Media::factory()->temporaryUpload($workspace)->create([
        'mime_type' => 'image/png',
        'original_filename' => "{$designId}.png",
        'path' => "uploads/{$designId}.png",
        'meta' => ['width' => 1080, 'height' => 1080, 'source' => 'canva', 'source_meta' => ['design_id' => $designId], ...$edits],
    ]);
    $importId = (string) Str::uuid();
    MediaImportStatus::pending($importId, $user->id, $workspace->id, $replaces);
    MediaImportStatus::complete($importId, $media);

    return ['import' => $importId, 'media' => $media];
}

/**
 * Stubs window.open, runs `$open` (a click that opens the Canva popup) and
 * returns the popup URL's path and query.
 *
 * @return array{path: string, query: array<string, string>}
 */
function editInCanvaStubbedPopup(mixed $page, Closure $open): array
{
    $page->script('window.__canvaOpened = []; window.open = (url) => { window.__canvaOpened.push(url); return window; }; true;');

    $open();

    $opened = $page->script('window.__canvaOpened');
    parse_str((string) parse_url($opened[0], PHP_URL_QUERY), $query);

    expect($opened)->toHaveCount(1);

    return ['path' => (string) parse_url($opened[0], PHP_URL_PATH), 'query' => $query];
}

function editInCanvaDeliver(mixed $page, string $nonce, string $importId, ?string $replaces): void
{
    $replacesJs = $replaces === null ? 'null' : "'{$replaces}'";

    $page->script(<<<JS
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'media-source-popup', source: 'canva', nonce: '{$nonce}', success: true, import_id: '{$importId}', replaces: {$replacesJs} },
        }));
        true;
    JS);
}

function editInCanvaPickSquare(mixed $page, string $toolbarPrefix): string
{
    ['query' => $query] = editInCanvaStubbedPopup($page, function () use ($page, $toolbarPrefix): void {
        $page->click("@{$toolbarPrefix}-media-source-menu");
        waitForEditInCanvaTestId($page, 'media-source-canva');
        $page->click('@media-source-canva');
        waitForEditInCanvaTestId($page, 'canva-preset-square');
        $page->click('@canva-preset-square');
    });

    return (string) data_get($query, 'nonce');
}

function editInCanvaTileSrc(mixed $page, string $prefix, int $index): ?string
{
    return $page->script("document.querySelector('[data-testid=\"{$prefix}-media-item-{$index}\"] img')?.getAttribute('src') ?? null");
}

test('the Canva button shows only on Canva-made tiles and replaces the tile in place in the right tray', function () {
    Storage::fake();
    config()->set([
        'services.unsplash.access_key' => null,
        'services.google_media.client_id' => null,
        'services.google_media.api_key' => null,
        'services.google_media.app_id' => null,
        'services.canva.client_id' => 'canva-client-id',
        'services.canva.client_secret' => 'canva-client-secret',
        'trypost.media_sources.canva.enabled' => true,
    ]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $this->actingAs($user);

    $tags = [['username' => 'trypost', 'x' => 0.25, 'y' => 0.75]];
    $shared = editInCanvaBrowserImport($user, $workspace, 'DAF-shared', edits: ['alt_text' => 'Launch banner', 'user_tags' => $tags]);
    $sharedEdited = editInCanvaBrowserImport($user, $workspace, 'DAF-shared-edited', $shared['media']->id);
    $channelMade = editInCanvaBrowserImport($user, $workspace, 'DAF-channel');
    $channelEdited = editInCanvaBrowserImport($user, $workspace, 'DAF-channel-edited', $channelMade['media']->id);

    $page = visit(route('app.posts.create'));
    waitForEditInCanvaCondition($page, "document.querySelector('[data-testid=\"composer-add-account\"]')?.getBoundingClientRect().height > 0", attempts: 300);

    editInCanvaDeliver($page, editInCanvaPickSquare($page, 'composer-base'), $shared['import'], null);
    waitForEditInCanvaTestId($page, 'composer-media-item-0');

    $png = base64_encode((string) file_get_contents(base_path('tests/fixtures/crop-quadrants.png')));
    $page->script(<<<JS
        (() => {
            const bytes = Uint8Array.from(atob('{$png}'), (character) => character.charCodeAt(0));
            const input = document.querySelector('[data-testid="composer-file-input"]');
            const transfer = new DataTransfer();
            transfer.items.add(new File([bytes], 'uploaded.png', { type: 'image/png' }));
            input.files = transfer.files;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        })();
    JS);
    waitForEditInCanvaTestId($page, 'composer-media-item-1');

    $uploadedSrc = editInCanvaTileSrc($page, 'composer', 1);

    $page->assertPresent('@composer-edit-canva-0')
        ->assertMissing('@composer-edit-canva-1')
        ->assertAttribute('@composer-edit-canva-0', 'aria-label', 'Edit DAF-shared.png in Canva')
        ->hover('@composer-dropzone');
    $page->script('document.activeElement?.blur(); true;');
    waitForEditInCanvaCondition($page, "getComputedStyle(document.querySelector('[data-testid=\"composer-edit-canva-0\"]').parentElement).opacity === '0'");
    expect($page->script("getComputedStyle(document.querySelector('[data-testid=\"composer-edit-canva-0\"]').parentElement).opacity"))->toBe('0');

    $page->hover('@composer-media-item-0');
    waitForEditInCanvaCondition($page, "getComputedStyle(document.querySelector('[data-testid=\"composer-edit-canva-0\"]').parentElement).opacity === '1'");
    expect($page->script("getComputedStyle(document.querySelector('[data-testid=\"composer-edit-canva-0\"]').parentElement).opacity"))->toBe('1');

    ['path' => $path, 'query' => $query] = editInCanvaStubbedPopup($page, fn () => $page->click('@composer-edit-canva-0'));

    expect($path)->toBe(route('app.integrations.canva.designs.edit', [], false))
        ->and(data_get($query, 'media'))->toBe($shared['media']->id)
        ->and(data_get($query, 'nonce'))->toMatch('/^[A-Za-z0-9_-]{16,64}$/');

    $exporting = (string) Str::uuid();
    MediaImportStatus::pending($exporting, $user->id, $workspace->id, $shared['media']->id);
    editInCanvaDeliver($page, (string) data_get($query, 'nonce'), $exporting, $shared['media']->id);
    waitForEditInCanvaTestId($page, 'composer-replacing-0');

    $page->assertPresent('@composer-replacing-0')
        ->assertMissing('@composer-import-item')
        ->assertMissing('@composer-replacing-1');
    expect(editInCanvaTileSrc($page, 'composer', 0))->toBe($shared['media']->url)
        ->and($page->script("document.querySelectorAll('[data-testid^=\"composer-media-item-\"]').length"))->toBe(2);

    MediaImportStatus::complete($exporting, $sharedEdited['media']);
    waitForEditInCanvaCondition($page, "document.querySelector('[data-testid=\"composer-media-item-0\"] img')?.getAttribute('src') === '{$sharedEdited['media']->url}'");

    expect(editInCanvaTileSrc($page, 'composer', 0))->toBe($sharedEdited['media']->url)
        ->and(editInCanvaTileSrc($page, 'composer', 1))->toBe($uploadedSrc)
        ->and($page->script("document.querySelectorAll('[data-testid^=\"composer-media-item-\"]').length"))->toBe(2);

    $page->click('@composer-add-account')
        ->click("@composer-account-option-{$channel->id}");
    waitForEditInCanvaTestId($page, "composer-{$channel->id}-media-item-1");

    editInCanvaDeliver($page, editInCanvaPickSquare($page, "composer-{$channel->id}"), $channelMade['import'], null);
    waitForEditInCanvaTestId($page, "composer-{$channel->id}-media-item-2");

    $page->assertPresent("@composer-{$channel->id}-edit-canva-0")
        ->assertMissing("@composer-{$channel->id}-edit-canva-1")
        ->assertPresent("@composer-{$channel->id}-edit-canva-2");

    ['query' => $channelQuery] = editInCanvaStubbedPopup($page, fn () => $page->click("@composer-{$channel->id}-edit-canva-2"));

    expect(data_get($channelQuery, 'media'))->toBe($channelMade['media']->id);

    editInCanvaDeliver($page, (string) data_get($channelQuery, 'nonce'), $channelEdited['import'], $channelMade['media']->id);
    waitForEditInCanvaCondition($page, "document.querySelector('[data-testid=\"composer-{$channel->id}-media-item-2\"] img')?.getAttribute('src') === '{$channelEdited['media']->url}'");

    expect(editInCanvaTileSrc($page, "composer-{$channel->id}", 2))->toBe($channelEdited['media']->url)
        ->and(editInCanvaTileSrc($page, "composer-{$channel->id}", 0))->toBe($sharedEdited['media']->url)
        ->and(editInCanvaTileSrc($page, "composer-{$channel->id}", 1))->toBe($uploadedSrc)
        ->and($page->script("document.querySelectorAll('[data-testid^=\"composer-{$channel->id}-media-item-\"]').length"))->toBe(3);

    waitForEditInCanvaCondition($page, "document.querySelector('[data-testid=\"composer-save-draft\"]')?.disabled === false");
    $page->click('@composer-save-draft');
    waitForEditInCanvaCondition($page, "!document.querySelector('[data-testid=\"post-composer-dialog\"]')", attempts: 200);

    $saved = Post::query()->where('workspace_id', $workspace->id)->sole()->media;

    expect(collect($saved)->pluck('id')->all())->toBe([$sharedEdited['media']->id, data_get($saved, '1.id'), $channelEdited['media']->id])
        ->and(data_get($saved, '0.meta.alt_text'))->toBe('Launch banner')
        ->and(data_get($saved, '0.meta.user_tags'))->toEqual($tags)
        ->and(data_get($saved, '2.meta.alt_text'))->toBeNull();

    $page->assertNoJavaScriptErrors();
});

test('the idea editor replaces a Canva tile in place and the save releases the replaced row', function () {
    Storage::fake();
    config()->set([
        'services.canva.client_id' => 'canva-client-id',
        'services.canva.client_secret' => 'canva-client-secret',
        'trypost.media_sources.canva.enabled' => true,
    ]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    $this->actingAs($user);

    $idea = Idea::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'idea_stage_id' => null, 'position' => 0, 'title' => 'Canva idea']);
    $original = Media::factory()->ownedByIdea($idea)->create([
        'mime_type' => 'image/png',
        'original_filename' => 'DAF-idea.png',
        'meta' => ['width' => 1080, 'height' => 1080, 'source' => 'canva', 'source_meta' => ['design_id' => 'DAF-idea']],
    ]);
    $plain = Media::factory()->ownedByIdea($idea)->create(['order' => 1]);
    $idea->update(['media' => [MediaItem::fromMedia($original)->toArray(), MediaItem::fromMedia($plain)->toArray()]]);
    Storage::put($original->path, 'original bytes');
    $edited = editInCanvaBrowserImport($user, $workspace, 'DAF-idea-edited', $original->id);

    $page = visit(route('app.create.ideas.show', $idea));
    waitForEditInCanvaTestId($page, 'idea-media-item-1');

    $page->assertPresent('@idea-edit-canva-0')
        ->assertMissing('@idea-edit-canva-1');

    ['path' => $path, 'query' => $query] = editInCanvaStubbedPopup($page, fn () => $page->click('@idea-edit-canva-0'));

    expect($path)->toBe(route('app.integrations.canva.designs.edit', [], false))
        ->and(data_get($query, 'media'))->toBe($original->id);

    editInCanvaDeliver($page, (string) data_get($query, 'nonce'), $edited['import'], $original->id);
    waitForEditInCanvaCondition($page, "document.querySelector('[data-testid=\"idea-media-item-0\"] img')?.getAttribute('src') === '{$edited['media']->url}'");

    expect(editInCanvaTileSrc($page, 'idea', 0))->toBe($edited['media']->url)
        ->and(editInCanvaTileSrc($page, 'idea', 1))->toBe($plain->url);

    $page->click('@idea-editor-save');
    waitForEditInCanvaCondition($page, "!document.querySelector('[data-testid=\"idea-editor\"]')", attempts: 200);

    expect(collect($idea->refresh()->media)->pluck('id')->all())->toBe([$edited['media']->id, $plain->id])
        ->and(Media::query()->find($original->id))->toBeNull()
        ->and($edited['media']->fresh()->idea_id)->toBe($idea->id);

    $page->assertNoJavaScriptErrors();
});
