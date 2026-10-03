<?php

declare(strict_types=1);

use App\Jobs\Media\ExportCanvaDesign;
use App\Models\Media;
use App\Models\MediaSourceConnection;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\MediaImportStatus;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function waitForComposerCanvaCondition(mixed $page, string $condition, int $attempts = 100): void
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

function waitForComposerCanvaTestId(mixed $page, string $testId, int $rounds = 1): void
{
    $visible = "document.querySelector('[data-testid=\"{$testId}\"]')?.getBoundingClientRect().height > 0";

    for ($round = 0; $round < $rounds && ! $page->script("Boolean({$visible})"); $round++) {
        waitForComposerCanvaCondition($page, $visible);
    }
}

/**
 * @return array{page: mixed, user: User, workspace: Workspace}
 */
function openComposerWithOnlyCanva(mixed $test): array
{
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
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $test->actingAs($user);

    $page = visit(route('app.posts.create'));
    waitForComposerCanvaCondition($page, "document.querySelector('[data-testid=\"composer-base-toolbar\"]')?.getBoundingClientRect().height > 0", attempts: 300);

    return ['page' => $page, 'user' => $user, 'workspace' => $workspace];
}

test('the Canva submenu lists the five presets with their sizes, Square first and default', function () {
    ['page' => $page] = openComposerWithOnlyCanva($this);

    $page->assertAttribute('@composer-base-media-source-main', 'aria-label', 'Select from Canva')
        ->click('@composer-base-media-source-menu');
    waitForComposerCanvaTestId($page, 'media-source-canva');
    $page->click('@media-source-canva');
    waitForComposerCanvaTestId($page, 'canva-preset-square');

    expect($page->script("[...document.querySelectorAll('[data-testid^=\"canva-preset-\"]:not([data-testid\$=\"-default\"])')].map((element) => element.dataset.testid)"))
        ->toBe(['canva-preset-square', 'canva-preset-portrait_4_5', 'canva-preset-portrait_3_4', 'canva-preset-landscape', 'canva-preset-story']);

    $page->assertSeeIn('@canva-preset-square', 'Square 1:1')
        ->assertSeeIn('@canva-preset-square', '1200 × 1200')
        ->assertPresent('@canva-preset-square-default')
        ->assertSeeIn('@canva-preset-portrait_4_5', '1080 × 1350')
        ->assertSeeIn('@canva-preset-landscape', '1200 × 627')
        ->assertSeeIn('@canva-preset-story', '1080 × 1920')
        ->assertNotPresent('@canva-preset-story-default')
        ->assertNoJavaScriptErrors();
});

/**
 * Stubs window.open, picks `$preset` from the Canva submenu and returns the
 * nonce the composer put on the popup URL. `$severed` makes the stub
 * behave like a popup Canva's COOP cut off (no reference, reads closed).
 */
function pickCanvaPresetWithStubbedPopup(mixed $page, string $preset, bool $severed): string
{
    $popup = $severed ? '({ closed: true })' : 'window';
    $page->script("window.__canvaOpened = []; window.open = (url) => { window.__canvaOpened.push(url); return {$popup}; }; true;");

    $page->click('@composer-base-media-source-menu');
    waitForComposerCanvaTestId($page, 'media-source-canva');
    $page->click('@media-source-canva');
    waitForComposerCanvaTestId($page, "canva-preset-{$preset}");
    $page->click("@canva-preset-{$preset}");

    $opened = $page->script('window.__canvaOpened');
    parse_str((string) parse_url($opened[0], PHP_URL_QUERY), $query);

    expect($opened)->toHaveCount(1)
        ->and(parse_url($opened[0], PHP_URL_PATH))->toBe(route('app.integrations.canva.designs.create', [], false))
        ->and(data_get($query, 'preset'))->toBe($preset)
        ->and(data_get($query, 'nonce'))->toMatch('/^[A-Za-z0-9_-]{16,64}$/');

    return (string) data_get($query, 'nonce');
}

function completedCanvaImport(User $user, Workspace $workspace): string
{
    $media = Media::factory()->temporaryUpload($workspace)->create(['mime_type' => 'image/png', 'original_filename' => 'canva-design.png']);
    $importId = (string) Str::uuid();
    MediaImportStatus::pending($importId, $user->id, $workspace->id);
    MediaImportStatus::complete($importId, $media);

    return $importId;
}

test('a preset opens the Canva popup and a message carrying its nonce becomes a tile', function () {
    Storage::fake();
    ['page' => $page, 'user' => $user, 'workspace' => $workspace] = openComposerWithOnlyCanva($this);
    $importId = completedCanvaImport($user, $workspace);

    $nonce = pickCanvaPresetWithStubbedPopup($page, 'portrait_4_5', severed: false);

    $page->script(<<<JS
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'media-source-popup', source: 'canva', nonce: 'another-popup-nonce-000', success: true, import_id: 'not-this-one', replaces: null },
        }));
        window.dispatchEvent(new MessageEvent('message', {
            origin: window.location.origin,
            data: { type: 'media-source-popup', source: 'canva', nonce: '{$nonce}', success: true, import_id: '{$importId}', replaces: null },
        }));
        true;
    JS);

    waitForComposerCanvaTestId($page, 'composer-media-item');
    $page->assertCount('@composer-media-item', 1)
        ->assertNoJavaScriptErrors();
});

test('with the opener severed, the real return page reaches the composer through the broadcast', function () {
    Queue::fake();
    ['page' => $page, 'user' => $user, 'workspace' => $workspace] = openComposerWithOnlyCanva($this);

    $nonce = pickCanvaPresetWithStubbedPopup($page, 'square', severed: true);

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $rsa = openssl_pkey_get_details($key)['rsa'];
    $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    Http::fake([
        config('trypost.media_sources.canva.api').'/connect/keys' => Http::response(['keys' => [
            ['kty' => 'RSA', 'kid' => 'browser-kid', 'alg' => 'RS256', 'use' => 'sig', 'n' => $encode($rsa['n']), 'e' => $encode($rsa['e'])],
        ]]),
    ]);
    $connection = MediaSourceConnection::factory()->for($user)->create();
    Cache::put('canva-design:browser-correlation', [
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'connection_id' => $connection->id,
        'nonce' => $nonce,
        'replaces_media_id' => null,
    ], 86400);
    $jwt = JWT::encode([
        'aud' => 'canva-client-id',
        'type' => 'rti',
        'exp' => now()->addDay()->getTimestamp(),
        'jti' => (string) Str::uuid(),
        'design_id' => 'DAF-browser',
        'correlation_state' => 'browser-correlation',
        'sub' => $connection->external_user_id,
        'team_id' => $connection->external_team_id,
    ], $pem, 'RS256', 'browser-kid');

    $popup = visit(route('app.integrations.canva.return', ['correlation_jwt' => $jwt]));
    waitForComposerCanvaTestId($popup, 'media-source-popup', rounds: 6);
    $popup->assertAttribute('@media-source-popup', 'data-success', 'true');

    waitForComposerCanvaTestId($page, 'composer-import-item', rounds: 6);
    $page->assertCount('@composer-import-item', 1)
        ->assertNoJavaScriptErrors();
    Queue::assertPushed(ExportCanvaDesign::class, fn (ExportCanvaDesign $job): bool => $job->designId === 'DAF-browser');
});

test('with every transport lost, the composer finds the return on the server', function () {
    Storage::fake();
    ['page' => $page, 'user' => $user, 'workspace' => $workspace] = openComposerWithOnlyCanva($this);
    $importId = completedCanvaImport($user, $workspace);

    $nonce = pickCanvaPresetWithStubbedPopup($page, 'story', severed: true);

    Cache::put("canva-return:{$user->id}:{$nonce}", [
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'import_id' => $importId,
        'replaces' => null,
    ], 3600);

    waitForComposerCanvaTestId($page, 'composer-media-item', rounds: 12);
    $page->assertCount('@composer-media-item', 1)
        ->assertNoJavaScriptErrors();
});
