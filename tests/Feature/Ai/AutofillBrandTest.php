<?php

declare(strict_types=1);

use App\Actions\Ai\AutofillBrand;
use App\Ai\Agents\BrandAnalyzer;
use App\Services\Brand\BrandMetadata;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    // Run tests without LLM credentials so the deterministic fallback is exercised.
    config()->set('services.gemini.api_key', '');
    config()->set('services.openai.api_key', '');

    $this->autofill = fn (string $url) => app(AutofillBrand::class)($url);
});

test('extracts name, description, language, and logo from meta tags', function () {
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
              <title>Acme Coffee | The best beans online</title>
              <meta name="description" content="Premium artisan coffee beans shipped worldwide.">
              <meta property="og:site_name" content="Acme Coffee">
              <meta property="og:image" content="https://example.com/og.png">
              <link rel="apple-touch-icon" href="https://example.com/apple-touch-icon.png">
              <link rel="icon" sizes="512x512" href="/icon-512.png">
            </head>
            <body>Welcome</body>
            </html>
        HTML, 200),
        'example.com/icon-512.png' => Http::response(file_get_contents(__DIR__.'/../../fixtures/1x1.png'), 200, ['Content-Type' => 'image/png']),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->name)->toBe('Acme Coffee');
    expect($result->description)->toBe('Premium artisan coffee beans shipped worldwide.');
    expect($result->language)->toBe('pt-BR');
    // The 512x512 PNG favicon wins over the unsized apple-touch-icon; og:image is ignored.
    expect($result->logoUrl)->toBe('https://example.com/icon-512.png');
});

test('falls back to /favicon.ico when no icon link is declared', function () {
    Http::fake([
        'example.com' => Http::response('<html><head><title>Foo</title></head></html>', 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->logoUrl)->toBe('https://example.com/favicon.ico');
});

test('ignores og:image when no icon links are present', function () {
    Http::fake([
        'example.com/page' => Http::response(<<<'HTML'
            <html>
            <head>
              <title>Marketing</title>
              <meta property="og:image" content="https://example.com/social-card.png">
            </head>
            </html>
        HTML, 200),
    ]);

    $result = ($this->autofill)('https://example.com/page');

    // og:image is NOT used — falls back to /favicon.ico at the origin instead.
    expect($result->logoUrl)->toBe('https://example.com/favicon.ico');
});

test('falls back to title without og:site_name', function () {
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <html lang="en">
            <head>
              <title>Super SaaS | Landing page</title>
              <meta name="description" content="A simple product.">
            </head>
            <body></body>
            </html>
        HTML, 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->name)->toBe('Super SaaS');
    expect($result->language)->toBe('en');
});

test('normalizes various language codes to supported locales', function (string $lang, ?string $expected) {
    Http::fake([
        'example.com' => Http::response("<html lang=\"{$lang}\"><head><title>X</title></head></html>", 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->language)->toBe($expected);
})->with([
    // Every supported language, detected from its primary subtag.
    ['en', 'en'],
    ['pt', 'pt-BR'],
    ['es', 'es'],
    ['fr', 'fr'],
    ['de', 'de'],
    ['it', 'it'],
    ['nl', 'nl'],
    ['pl', 'pl'],
    ['el', 'el'],
    ['ja', 'ja'],
    ['ko', 'ko'],
    ['zh', 'zh'],
    ['ru', 'ru'],
    ['tr', 'tr'],
    ['ar', 'ar'],
    // Region/script subtags still resolve to the supported language.
    ['pt-PT', 'pt-BR'],
    ['en-US', 'en'],
    ['es-MX', 'es'],
    ['ja-JP', 'ja'],
    ['zh-Hans', 'zh'],
    // Unsupported languages stay null.
    ['sv', null],
]);

test('rejects non-http schemes', function () {
    expect(fn () => ($this->autofill)('ftp://example.com'))
        ->toThrow(RuntimeException::class);
});

test('rejects private network addresses', function () {
    expect(fn () => ($this->autofill)('http://127.0.0.1'))
        ->toThrow(RuntimeException::class);

    expect(fn () => ($this->autofill)('http://192.168.1.1'))
        ->toThrow(RuntimeException::class);
});

test('adds https:// when scheme is missing', function () {
    Http::fake([
        'example.com' => Http::response('<html><head><title>ok</title></head></html>', 200),
    ]);

    ($this->autofill)('example.com');

    Http::assertSent(fn (HttpRequest $req) => str_starts_with($req->url(), 'https://example.com'));
});

test('falls back to domain-derived name when site has no meta tags', function () {
    Http::fake([
        'example.com' => Http::response('<html><body></body></html>', 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->name)->toBe('Example');
    expect($result->description)->toBeNull();
    expect($result->language)->toBeNull();
    // logoUrl always falls back to /favicon.ico since that URL exists on most sites.
    expect($result->logoUrl)->toBe('https://example.com/favicon.ico');
});

test('falls back to domain-derived name when title is a tagline with no separator', function () {
    Http::fake([
        'sendkit.dev' => Http::response(<<<'HTML'
            <html>
            <head>
              <title>Email API, SMTP & Marketing Platform for Developers & AI Agents</title>
            </head>
            <body></body>
            </html>
        HTML, 200),
    ]);

    $result = ($this->autofill)('https://sendkit.dev');

    expect($result->name)->toBe('Sendkit');
});

test('extracts brand color from theme-color meta', function () {
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
              <title>Acme</title>
              <meta name="theme-color" content="#0ea5e9">
            </head>
            <body></body>
            </html>
        HTML, 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->brandColor)->toBe('#0ea5e9');
});

test('extracts colors from CSS custom properties', function () {
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
              <title>Acme</title>
              <style>
                :root {
                    --primary: #ff5722;
                    --background: #0b0f19;
                    --foreground: #e2e8f0;
                }
              </style>
            </head>
            <body></body>
            </html>
        HTML, 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->brandColor)->toBe('#ff5722');
    expect($result->backgroundColor)->toBe('#0b0f19');
    expect($result->textColor)->toBe('#e2e8f0');
});

test('falls back to body { background } and body { color } rules', function () {
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
              <title>Acme</title>
              <style>
                body {
                    background-color: #ffffff;
                    color: #1f2937;
                }
              </style>
            </head>
            <body></body>
            </html>
        HTML, 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->backgroundColor)->toBe('#ffffff');
    expect($result->textColor)->toBe('#1f2937');
});

test('extracts colors from external stylesheets', function () {
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
              <title>Acme</title>
              <link rel="stylesheet" href="/app.css">
            </head>
            <body></body>
            </html>
        HTML, 200),
        'example.com/app.css' => Http::response(':root { --primary: #1d4ed8; --background: #f8fafc; }', 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->brandColor)->toBe('#1d4ed8');
    expect($result->backgroundColor)->toBe('#f8fafc');
});

test('falls back to dominant logo color when CSS has no signal', function () {
    // Logo fixture is a solid blue PNG. CSS in the page has no semantic hooks,
    // so AutofillBrand should reach into the logo and grab #1e6fff via
    // LogoColorExtractor.
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
              <title>Acme</title>
              <link rel="icon" sizes="32x32" href="/logo.png">
            </head>
            <body>Hello</body>
            </html>
        HTML, 200),
        'example.com/logo.png' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/blue-logo.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->brandColor)->toBe('#1e6fff');
});

test('returns null when CSS contains no extractable colour values', function () {
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <!DOCTYPE html>
            <html>
            <head>
              <title>Acme</title>
              <meta name="theme-color" content="rgb(255, 0, 0)">
              <style>:root { --primary: notacolor; --background: red; }</style>
            </head>
            <body></body>
            </html>
        HTML, 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    // theme-color tier-1 and CSS-var tier-2 reject the rgb()/named values;
    // the frequency tier-3 finds nothing extractable in this CSS.
    expect($result->brandColor)->toBeNull();
    expect($result->backgroundColor)->toBeNull();
});

test('falls back to CSS colour frequency when no semantic var is exposed', function () {
    // Tailwind/utility-style CSS with no --primary var: the brand colour just
    // appears many times across utility classes. Frequency tier picks it up.
    $css = str_repeat('.btn-primary { background-color: #2563eb; } ', 30);
    Http::fake([
        'example.com' => Http::response(<<<HTML
            <!DOCTYPE html>
            <html>
            <head>
              <title>Acme</title>
              <style>{$css}</style>
            </head>
            <body></body>
            </html>
        HTML, 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->brandColor)->toBe('#2563eb');
});

test('throws when upstream site returns an error', function () {
    Http::fake([
        'example.com' => Http::response('', 500),
    ]);

    expect(fn () => ($this->autofill)('https://example.com'))
        ->toThrow(RuntimeException::class);
});

test('when llm is configured, polishes description/tone/language/voice_notes via BrandAnalyzer', function () {
    config()->set('services.gemini.api_key', 'fake-key');
    config()->set('ai.default', 'gemini');

    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <html lang="en">
            <head>
              <title>Widget Co</title>
              <meta name="description" content="A very terse seo blurb.">
            </head>
            <body>
              <main>
                <h1>Build widgets faster</h1>
                <p>Widget Co helps small teams ship production widgets 10x faster without writing boilerplate.</p>
              </main>
            </body>
            </html>
        HTML, 200),
    ]);

    BrandAnalyzer::fake([
        [
            'description' => 'Widget Co helps small teams ship production widgets faster.',
            'language' => 'en',
            // A bogus value (dropped) and a second POV (dropped — single-select).
            'voice_traits' => ['third_person', 'first_person', 'direct', 'no_hype', 'not_a_trait'],
        ],
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->description)->toBe('Widget Co helps small teams ship production widgets faster.');
    expect($result->language)->toBe('en');
    expect($result->toArray()['brand_voice_traits'])->toBe(['third_person', 'direct', 'no_hype']);
});

test('LLM language detection wins and carries any supported language, not just en/es/pt-BR', function () {
    config()->set('services.gemini.api_key', 'fake-key');
    config()->set('ai.default', 'gemini');

    // The <html lang> declares "en", so the deterministic extractor yields 'en'.
    // The LLM reads the actual German body and returns 'de'. Since the fixture's
    // deterministic value differs from the LLM's, this isolates the mergeLlm
    // precedence: the LLM value must win, and it must be a language beyond the
    // original en/es/pt-BR set.
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <html lang="en">
            <head>
              <title>Beispiel GmbH</title>
              <meta name="description" content="Kurze Beschreibung.">
            </head>
            <body><main><p>Wir bauen Widgets für kleine Teams.</p></main></body>
            </html>
        HTML, 200),
    ]);

    BrandAnalyzer::fake([
        [
            'description' => 'Beispiel GmbH baut Widgets für kleine Teams.',
            'language' => 'de',
            'voice_traits' => ['third_person'],
        ],
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->language)->toBe('de');
});

test('when llm is not configured, falls back to meta tags only', function () {
    // beforeEach already cleared api keys.
    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <html lang="pt-BR">
            <head>
              <title>Marca</title>
              <meta name="description" content="Uma descrição curta.">
            </head>
            <body><main><p>hello</p></main></body>
            </html>
        HTML, 200),
    ]);

    // Fail loud if BrandAnalyzer is called.
    BrandAnalyzer::fake()->preventStrayPrompts();

    $result = ($this->autofill)('https://example.com');

    expect($result->description)->toBe('Uma descrição curta.');
    expect($result->language)->toBe('pt-BR');
});

test('falls back to meta tags when BrandAnalyzer throws', function () {
    config()->set('services.gemini.api_key', 'fake-key');
    config()->set('ai.default', 'gemini');

    Http::fake([
        'example.com' => Http::response(<<<'HTML'
            <html lang="en">
            <head>
              <title>Acme</title>
              <meta name="description" content="Fallback desc.">
            </head>
            <body><main><p>hi</p></main></body>
            </html>
        HTML, 200),
    ]);

    BrandAnalyzer::fake([
        fn () => throw new RuntimeException('LLM went down'),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->description)->toBe('Fallback desc.');
    expect($result->language)->toBe('en');
});

test('toArray swaps site background and text colours for the image palette fields', function () {
    $metadata = new BrandMetadata(
        brandColor: '#eab308',
        backgroundColor: '#ffffff',
        textColor: '#1f2937',
    );

    $array = $metadata->toArray();

    expect($array['background_color'])->toBe('#1f2937')
        ->and($array['text_color'])->toBe('#ffffff')
        ->and($array['brand_color'])->toBe('#eab308')
        ->and($metadata->backgroundColor)->toBe('#ffffff')
        ->and($metadata->textColor)->toBe('#1f2937');
});

test('BrandMetadata toArray exposes the shape the controller expects', function () {
    Http::fake([
        'example.com' => Http::response('<html lang="en"><head><title>Foo</title></head></html>', 200),
    ]);

    $result = ($this->autofill)('https://example.com');

    expect($result->toArray())->toHaveKeys([
        'name',
        'brand_description',
        'content_language',
        'brand_color',
        'background_color',
        'text_color',
        'logo_url',
        'brand_voice_traits',
    ]);
});
