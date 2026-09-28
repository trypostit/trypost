<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WebdavService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    config([
        'services.webdav.url' => 'https://cloud.example.com/remote.php/dav/files/team',
        'services.webdav.username' => 'team',
        'services.webdav.password' => 'secret',
        'services.webdav.root' => '',
        'services.webdav.label' => 'Files',
        'services.webdav.max_import_bytes' => 512 * 1024 * 1024,
    ]);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
});

function davListing(): string
{
    return <<<'XML'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/files/team/</d:href>
    <d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/team/Bilder/</d:href>
    <d:propstat><d:prop>
      <d:displayname>Bilder</d:displayname>
      <d:resourcetype><d:collection/></d:resourcetype>
    </d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/team/plakat.jpg</d:href>
    <d:propstat><d:prop>
      <d:displayname>plakat.jpg</d:displayname>
      <d:getcontentlength>2048</d:getcontentlength>
      <d:getcontenttype>image/jpeg</d:getcontenttype>
      <d:resourcetype/>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML;
}

// ------------------------------------------------------------- browsing ---

test('browsing lists folders before files', function () {
    Http::fake(['cloud.example.com/*' => Http::response(davListing(), 207)]);

    $response = $this->actingAs($this->user)->getJson(route('app.assets.webdav.browse'));

    $response->assertOk();

    $entries = $response->json('entries');

    expect($entries)->toHaveCount(2)
        ->and($entries[0]['name'])->toBe('Bilder')
        ->and($entries[0]['is_directory'])->toBeTrue()
        ->and($entries[1]['name'])->toBe('plakat.jpg')
        ->and($entries[1]['is_directory'])->toBeFalse()
        ->and($entries[1]['size'])->toBe(2048);
});

test('the listing offers a way back up but not above the root', function () {
    Http::fake(['cloud.example.com/*' => Http::response(davListing(), 207)]);

    $atRoot = $this->actingAs($this->user)->getJson(route('app.assets.webdav.browse'));
    expect($atRoot->json('parent'))->toBeNull();

    $inFolder = $this->actingAs($this->user)
        ->getJson(route('app.assets.webdav.browse', ['path' => 'Bilder/2026']));
    expect($inFolder->json('parent'))->toBe('Bilder');
});

test('browsing is closed while no share is configured', function () {
    config(['services.webdav.url' => null]);

    $this->actingAs($this->user)
        ->getJson(route('app.assets.webdav.browse'))
        ->assertNotFound();
});

test('browsing requires signing in', function () {
    $this->getJson(route('app.assets.webdav.browse'))->assertUnauthorized();
});

// -------------------------------------------------------------- importing ---

/**
 * Real bytes, because the import determines the type from the file itself.
 */
function davImageBytes(): string
{
    $image = imagecreatetruecolor(4, 4);
    ob_start();
    imagepng($image);
    imagedestroy($image);

    return (string) ob_get_clean();
}

test('importing a file creates a media record in the workspace', function () {
    Http::fake([
        'cloud.example.com/*' => Http::response(davImageBytes(), 200, ['Content-Type' => 'image/png']),
    ]);

    $response = $this->actingAs($this->user)->postJson(route('app.assets.webdav.store'), [
        'paths' => ['Bilder/plakat.jpg'],
    ]);

    $response->assertOk();

    expect($this->workspace->media()->count())->toBe(1)
        ->and($this->workspace->media()->first()->original_filename)->toContain('plakat');
});

test('importing is closed while no share is configured', function () {
    config(['services.webdav.username' => null]);

    $this->actingAs($this->user)
        ->postJson(route('app.assets.webdav.store'), ['paths' => ['a.jpg']])
        ->assertNotFound();
});

test('importing rejects an empty or oversized selection', function (array $paths) {
    $this->actingAs($this->user)
        ->postJson(route('app.assets.webdav.store'), ['paths' => $paths])
        ->assertStatus(422);
})->with([
    [[]],
    [array_fill(0, 21, 'a.jpg')],
]);

// ------------------------------------------------------------- path safety ---

test('a path can never climb out of the configured share', function (string $attempt) {
    // Traversal is dropped rather than rejected, so a mangled path lands on the
    // root instead of somewhere else on the server.
    $normalized = app(WebdavService::class)->normalize($attempt);

    expect($normalized)->not->toContain('..')
        ->and($normalized)->not->toStartWith('/');
})->with([
    '../../../etc/passwd',
    '/etc/passwd',
    'Bilder/../../../../root/.ssh/id_rsa',
    '..\\..\\windows\\system32',
    './././..',
]);

test('the request that leaves the server stays inside the share', function () {
    Http::fake(['cloud.example.com/*' => Http::response(davListing(), 207)]);

    $this->actingAs($this->user)->getJson(route('app.assets.webdav.browse', [
        'path' => '../../../etc',
    ]))->assertOk();

    Http::assertSent(function ($request) {
        // Everything after the configured collection must still be below it.
        expect($request->url())->toStartWith('https://cloud.example.com/remote.php/dav/files/team')
            ->and($request->url())->not->toContain('..');

        return true;
    });
});

test('a share root confines browsing to that folder', function () {
    config(['services.webdav.root' => 'Marketing']);

    Http::fake(['cloud.example.com/*' => Http::response(davListing(), 207)]);

    $this->actingAs($this->user)->getJson(route('app.assets.webdav.browse'))->assertOk();

    // Asserted whole, not as a fragment: a URL missing the separator after the
    // root still contains the root, and would pass a str_contains check while
    // pointing at a path that does not exist.
    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.example.com/remote.php/dav/files/team/Marketing');
});

test('a path below the configured root keeps its separators', function (string $root, string $path, string $expected) {
    // The bug this pins down: building the URL by concatenation dropped the
    // slash between the root and the path, so every file below a configured
    // root 404'd while the listing above it worked.
    config(['services.webdav.root' => $root]);

    Http::fake(['cloud.example.com/*' => Http::response(davListing(), 207)]);

    $this->actingAs($this->user)
        ->getJson(route('app.assets.webdav.browse', ['path' => $path]))
        ->assertOk();

    Http::assertSent(fn ($request) => $request->url() === $expected);
})->with([
    'a file below a root' => ['Marketing', 'plakat.jpg', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/plakat.jpg'],
    'a folder below a root' => ['Marketing', 'Bilder', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/Bilder'],
    'nested below a root' => ['Marketing', 'Bilder/2026', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/Bilder/2026'],
    'a nested root' => ['Marketing/Social', 'plakat.jpg', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/Social/plakat.jpg'],
    'no root at all' => ['', 'Bilder/plakat.jpg', 'https://cloud.example.com/remote.php/dav/files/team/Bilder/plakat.jpg'],
    'a name that needs encoding' => ['Marketing', 'Bühne 2026.jpg', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/B%C3%BChne%202026.jpg'],
    // The characters a band name actually contains. Each one ends the path
    // early, or changes its meaning, if it reaches the URL unencoded.
    'an ampersand' => ['Marketing', 'Rock & Roll.jpg', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/Rock%20%26%20Roll.jpg'],
    'a hash' => ['Marketing', 'Set #3.jpg', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/Set%20%233.jpg'],
    'a question mark' => ['Marketing', 'Was solls?.jpg', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/Was%20solls%3F.jpg'],
    'a plus sign' => ['Marketing', 'Süß+Sauer.jpg', 'https://cloud.example.com/remote.php/dav/files/team/Marketing/S%C3%BC%C3%9F%2BSauer.jpg'],
]);

test('a download below a configured root reaches the file', function () {
    // The listing worked while the download 404'd, because only the download
    // path carried a segment after the root.
    config(['services.webdav.root' => 'Marketing']);

    Http::fake(['cloud.example.com/*' => Http::response(davImageBytes(), 200, ['Content-Type' => 'image/png'])]);

    $this->actingAs($this->user)
        ->postJson(route('app.assets.webdav.store'), ['paths' => ['plakat.jpg']])
        ->assertOk();

    Http::assertSent(fn ($request) => $request->method() === 'GET'
        && $request->url() === 'https://cloud.example.com/remote.php/dav/files/team/Marketing/plakat.jpg');
});

test('a file the editor cannot use is refused, not crashed on', function () {
    // A share holds spreadsheets and archives too; picking one has to come back
    // as a refusal rather than a server error.
    Http::fake([
        'cloud.example.com/*' => Http::response('name;amount\n', 200, ['Content-Type' => 'text/csv']),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.assets.webdav.store'), ['paths' => ['budget.csv']])
        ->assertStatus(422);

    expect($this->workspace->media()->count())->toBe(0);
});

test('one unusable file does not discard the rest of a selection', function () {
    // Picking twenty holiday photos and one stray spreadsheet should not cost
    // the twenty photos - and the person picking has to learn which one failed.
    Http::fake(function ($request) {
        return str_contains($request->url(), 'budget.csv')
            ? Http::response("name;amount\n", 200, ['Content-Type' => 'text/csv'])
            : Http::response(davImageBytes(), 200, ['Content-Type' => 'image/png']);
    });

    $response = $this->actingAs($this->user)->postJson(route('app.assets.webdav.store'), [
        'paths' => ['Bilder/plakat.jpg', 'budget.csv', 'Bilder/buehne.jpg'],
    ]);

    $response->assertOk()
        ->assertJsonCount(2, 'media')
        ->assertJsonCount(1, 'failed')
        ->assertJsonPath('failed.0.name', 'budget.csv');

    expect($this->workspace->media()->count())->toBe(2);
});

test('a selection in which nothing survives is an error, not an empty success', function () {
    Http::fake([
        'cloud.example.com/*' => Http::response("name;amount\n", 200, ['Content-Type' => 'text/csv']),
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.assets.webdav.store'), ['paths' => ['budget.csv', 'plan.csv']])
        ->assertStatus(422);

    expect($this->workspace->media()->count())->toBe(0);
});

test('an oversized file is refused before its body is fetched', function () {
    // A share holds film rushes next to snapshots. The refusal has to happen on
    // the announced size, not after half a gigabyte has crossed the wire.
    config(['services.webdav.max_import_bytes' => 1024]);

    Http::fake(function ($request) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200, ['Content-Length' => '4096'])
            : Http::response(davImageBytes(), 200, ['Content-Type' => 'image/png']);
    });

    $this->actingAs($this->user)
        ->postJson(route('app.assets.webdav.store'), ['paths' => ['rohmaterial.mp4']])
        ->assertStatus(422);

    Http::assertNotSent(fn ($request) => $request->method() === 'GET');

    expect($this->workspace->media()->count())->toBe(0);
});

test('a share that understates a file size is still caught after the download', function () {
    // The HEAD answer is the share's word, not a guarantee. What actually
    // landed on disk is the fact, and it is what decides.
    config(['services.webdav.max_import_bytes' => 64]);

    Http::fake(function ($request) {
        return $request->method() === 'HEAD'
            ? Http::response('', 200, ['Content-Length' => '12'])
            : Http::response(str_repeat('x', 4096), 200, ['Content-Type' => 'image/png']);
    });

    $this->actingAs($this->user)
        ->postJson(route('app.assets.webdav.store'), ['paths' => ['klein.png']])
        ->assertStatus(422);

    // The body was fetched - the guard is the one after it, not the one before.
    Http::assertSent(fn ($request) => $request->method() === 'GET');

    expect($this->workspace->media()->count())->toBe(0);
});

test('a folder whose name needs encoding does not list itself', function () {
    // The first entry of a PROPFIND is the collection itself, recognised by
    // comparing hrefs. The server sends them decoded while the URL we built is
    // percent-encoded, so a folder called "Bilder 2026" used to appear inside
    // itself - and clicking it went nowhere.
    Http::fake(['cloud.example.com/*' => Http::response(<<<'XML'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/files/team/Bilder 2026/</d:href>
    <d:propstat><d:prop>
      <d:displayname>Bilder 2026</d:displayname>
      <d:resourcetype><d:collection/></d:resourcetype>
    </d:prop></d:propstat>
  </d:response>
  <d:response>
    <d:href>/remote.php/dav/files/team/Bilder 2026/plakat.jpg</d:href>
    <d:propstat><d:prop>
      <d:displayname>plakat.jpg</d:displayname>
      <d:getcontentlength>2048</d:getcontentlength>
      <d:getcontenttype>image/jpeg</d:getcontenttype>
      <d:resourcetype/>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML, 207)]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.assets.webdav.browse', ['path' => 'Bilder 2026']));

    $response->assertOk()->assertJsonCount(1, 'entries');

    expect($response->json('entries.0.name'))->toBe('plakat.jpg');
});

test('an empty folder whose name needs encoding comes back empty', function () {
    // The same mismatch made an empty folder look like it held one item:
    // itself.
    Http::fake(['cloud.example.com/*' => Http::response(<<<'XML'
<?xml version="1.0"?>
<d:multistatus xmlns:d="DAV:">
  <d:response>
    <d:href>/remote.php/dav/files/team/Leerer Ordner/</d:href>
    <d:propstat><d:prop>
      <d:displayname>Leerer Ordner</d:displayname>
      <d:resourcetype><d:collection/></d:resourcetype>
    </d:prop></d:propstat>
  </d:response>
</d:multistatus>
XML, 207)]);

    $this->actingAs($this->user)
        ->getJson(route('app.assets.webdav.browse', ['path' => 'Leerer Ordner']))
        ->assertOk()
        ->assertJsonCount(0, 'entries');
});
