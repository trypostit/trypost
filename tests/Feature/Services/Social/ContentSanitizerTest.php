<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Services\Social\ContentSanitizer;

/**
 * Every platform except X. Derived from the enum so a network added later is covered
 * without anyone remembering to list it here — defusing must stay X-only.
 *
 * @return array<string, array<int, Platform>>
 */
function platformsThatKeepLinks(): array
{
    $cases = array_filter(Platform::cases(), fn (Platform $platform): bool => $platform !== Platform::X);

    return array_combine(
        array_map(fn (Platform $platform): string => $platform->value, $cases),
        array_map(fn (Platform $platform): array => [$platform], $cases),
    );
}

beforeEach(function () {
    config()->set('trypost.platforms.x.defuse_links', true);
});

test('it strips html tags for plain text platforms', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>Hello <strong>world</strong></p>', Platform::Instagram);
    expect($result)->toBe('Hello world');
});

test('it converts bold to unicode for linkedin', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>Hello <strong>world</strong></p>', Platform::LinkedIn);
    expect($result)->toContain('𝘄𝗼𝗿𝗹𝗱');
});

test('it converts p tags to newlines', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>First paragraph</p><p>Second paragraph</p>', Platform::X);
    expect($result)->toBe("First paragraph\nSecond paragraph");
});

test('it converts br to newlines', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('Line one<br>Line two', Platform::Facebook);
    expect($result)->toBe("Line one\nLine two");
});

test('it decodes html entities', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('Tom &amp; Jerry &lt;3', Platform::X);
    expect($result)->toBe('Tom & Jerry <3');
});

test('it returns plain text unchanged', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('Just plain text', Platform::Instagram);
    expect($result)->toBe('Just plain text');
});

test('it returns an empty string for empty content on every platform', function (Platform $platform) {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize('', $platform))->toBe('');
})->with(Platform::cases());

test('it converts list items to dashes', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<ul><li>Item one</li><li>Item two</li></ul>', Platform::Facebook);
    expect($result)->toContain('- Item one');
    expect($result)->toContain('- Item two');
});

test('it preserves safe html for mastodon', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>Hello <strong>world</strong> and <em>italic</em></p>', Platform::Mastodon);
    expect($result)->toContain('<strong>world</strong>');
    expect($result)->toContain('<em>italic</em>');
    expect($result)->toContain('<p>');
});

test('it strips unsafe html for mastodon', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>Hello</p><script>alert("xss")</script><div>block</div>', Platform::Mastodon);
    expect($result)->toContain('<p>Hello</p>');
    expect($result)->not->toContain('<script>');
    expect($result)->not->toContain('<div>');
});

test('it preserves links for mastodon', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>Check <a href="https://example.com">this</a></p>', Platform::Mastodon);
    expect($result)->toContain('<a href="https://example.com">this</a>');
});

test('it keeps telegram-allowed html and converts strong/em', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>Hello <strong>world</strong> and <em>you</em></p>', Platform::Telegram);
    expect($result)->toBe('Hello <b>world</b> and <i>you</i>');
});

test('it strips disallowed tags but keeps links for telegram', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<div>see <a href="https://example.com">link</a></div><script>x</script>', Platform::Telegram);
    expect($result)->toBe('see <a href="https://example.com">link</a>x');
});

test('it escapes bare ampersands for telegram', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('Tom &amp; Jerry & friends', Platform::Telegram);
    expect($result)->toBe('Tom &amp; Jerry &amp; friends');
});

test('it drops anchors without an href for telegram', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>see <a>bare</a> and <a href="https://x.com">link</a></p>', Platform::Telegram);
    expect($result)->toBe('see bare and <a href="https://x.com">link</a>');
});

test('it preserves @username mentions as plain text for telegram', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('<p>Hey @durov and @TryPostBot</p>', Platform::Telegram);
    expect($result)->toBe('Hey @durov and @TryPostBot');
});

test('it defuses a bare link for x', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('Check acme.com today', Platform::X);
    expect($result)->toBe('Check acme(.)com today');
});

test('it strips the scheme when defusing a link for x', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('New post: https://acme.com/post', Platform::X);
    expect($result)->toBe('New post: acme(.)com/post');
});

test('it strips www when defusing a link for x', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('See http://www.acme.com', Platform::X);
    expect($result)->toBe('See acme(.)com');
});

test('it defuses every dot of a multi level host for x', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('Read blog.acme.com.br/x', Platform::X);
    expect($result)->toBe('Read blog(.)acme(.)com(.)br/x');
});

test('it keeps dots in the path when defusing for x', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('Download acme.com/file.pdf', Platform::X);
    expect($result)->toBe('Download acme(.)com/file.pdf');
});

test('it leaves text that only looks like a link untouched for x', function () {
    $sanitizer = new ContentSanitizer;
    $content = 'We run Node.js 3.5 here, e.g. in the final file.pdf.';
    expect($sanitizer->sanitize($content, Platform::X))->toBe($content);
});

test('it defuses every link in the same post for x', function () {
    $sanitizer = new ContentSanitizer;
    $result = $sanitizer->sanitize('First acme.com then other.dev/a', Platform::X);
    expect($result)->toBe('First acme(.)com then other(.)dev/a');
});

test('it does not defuse links for any platform other than x', function (Platform $platform) {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize('Check acme.com', $platform))->toContain('acme.com');
})->with(platformsThatKeepLinks());

test('it leaves links untouched for x when defusing is disabled', function () {
    config()->set('trypost.platforms.x.defuse_links', false);

    $sanitizer = new ContentSanitizer;
    expect($sanitizer->sanitize('Check acme.com', Platform::X))->toBe('Check acme.com');
});

test('it defuses every host shape for x', function (string $input, string $expected) {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize("See {$input} now", Platform::X))
        ->toBe("See {$expected} now");
})->with([
    // Bare host, no subdomain
    'gTLD' => ['acme.com', 'acme(.)com'],
    'ccTLD' => ['acme.br', 'acme(.)br'],
    'new gTLD' => ['acme.dev', 'acme(.)dev'],
    'two-level TLD' => ['acme.com.br', 'acme(.)com(.)br'],
    'two-level TLD (uk)' => ['acme.co.uk', 'acme(.)co(.)uk'],
    'two-level TLD (au)' => ['acme.com.au', 'acme(.)com(.)au'],
    'two-level TLD (org.br)' => ['acme.org.br', 'acme(.)org(.)br'],

    // One subdomain
    'sub + gTLD' => ['blog.acme.com', 'blog(.)acme(.)com'],
    'sub + ccTLD' => ['blog.acme.io', 'blog(.)acme(.)io'],
    'sub + two-level TLD' => ['blog.acme.com.br', 'blog(.)acme(.)com(.)br'],
    'sub + two-level TLD (uk)' => ['blog.acme.co.uk', 'blog(.)acme(.)co(.)uk'],

    // Two subdomains
    'sub.sub + gTLD' => ['org.blog.acme.com', 'org(.)blog(.)acme(.)com'],
    'sub.sub + two-level TLD' => ['org.blog.acme.com.br', 'org(.)blog(.)acme(.)com(.)br'],

    // Three subdomains
    'sub.sub.sub + gTLD' => ['a.org.blog.acme.com', 'a(.)org(.)blog(.)acme(.)com'],
    'sub.sub.sub + two-level TLD' => ['a.org.blog.acme.com.br', 'a(.)org(.)blog(.)acme(.)com(.)br'],

    // Scheme is dropped
    'https + bare' => ['https://acme.com', 'acme(.)com'],
    'http + bare' => ['http://acme.com', 'acme(.)com'],
    'https + two-level TLD' => ['https://acme.com.br', 'acme(.)com(.)br'],
    'https + sub' => ['https://blog.acme.com', 'blog(.)acme(.)com'],
    'https + sub.sub + two-level TLD' => ['https://org.blog.acme.com.br', 'org(.)blog(.)acme(.)com(.)br'],

    // www is dropped, everything else defused
    'www + bare' => ['www.acme.com', 'acme(.)com'],
    'www + two-level TLD' => ['www.acme.com.br', 'acme(.)com(.)br'],
    'https + www + bare' => ['https://www.acme.com', 'acme(.)com'],
    'https + www + two-level TLD' => ['https://www.acme.com.br', 'acme(.)com(.)br'],
    'www + sub' => ['www.blog.acme.com', 'blog(.)acme(.)com'],

    // Path, query and fragment survive untouched
    'path' => ['acme.com/post', 'acme(.)com/post'],
    'nested path' => ['acme.com.br/blog/2026/x', 'acme(.)com(.)br/blog/2026/x'],
    'path with dot' => ['acme.com/file.pdf', 'acme(.)com/file.pdf'],
    'query string' => ['acme.com/a?b=c&d=e', 'acme(.)com/a?b=c&d=e'],
    'fragment' => ['acme.com/a#section', 'acme(.)com/a#section'],
    'https + sub.sub + two-level TLD + path + query + fragment' => [
        'https://org.blog.acme.com.br/a/b?c=1#d',
        'org(.)blog(.)acme(.)com(.)br/a/b?c=1#d',
    ],

    // Casing is preserved
    'uppercase host' => ['ACME.COM', 'ACME(.)COM'],
    'mixed case host' => ['Blog.Acme.Com.Br', 'Blog(.)Acme(.)Com(.)Br'],

    // Hyphens are valid in labels
    'hyphenated host' => ['my-blog.acme-corp.com.br', 'my-blog(.)acme-corp(.)com(.)br'],

    // Surrounding punctuation must not be swallowed
    'trailing period' => ['acme.com.', 'acme(.)com.'],
    'trailing comma' => ['acme.com,', 'acme(.)com,'],
    'wrapped in parentheses' => ['(acme.com)', '(acme(.)com)'],
]);

test('it defuses several links of different shapes in one post for x', function () {
    $sanitizer = new ContentSanitizer;
    $content = 'Read https://blog.acme.com.br/x, see www.acme.io and acme.dev/docs';

    expect($sanitizer->sanitize($content, Platform::X))
        ->toBe('Read blog(.)acme(.)com(.)br/x, see acme(.)io and acme(.)dev/docs');
});

test('it leaves email addresses untouched for x', function () {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize('Email contact@acme.com.br today', Platform::X))
        ->toBe('Email contact@acme.com.br today');
});

test('it leaves non-link text untouched for x', function (string $input) {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize("About {$input} here", Platform::X))
        ->toBe("About {$input} here");
})->with([
    'js library' => ['Node.js'],
    'abbreviation' => ['e.g.'],
    'abbreviation (latin)' => ['i.e.'],
    'decimal number' => ['3.5'],
    'version number' => ['8.5.1'],
    'pdf file' => ['file.pdf'],
    'image file' => ['photo.png'],
    'ellipsis' => ['wait...'],
]);

test('it keeps every host shape intact on every platform other than x', function (string $input) {
    $sanitizer = new ContentSanitizer;

    foreach (platformsThatKeepLinks() as [$platform]) {
        expect($sanitizer->sanitize("See {$input}", $platform))->toContain($input);
    }
})->with([
    'bare' => ['acme.com'],
    'two-level TLD' => ['acme.com.br'],
    'sub' => ['blog.acme.com'],
    'sub.sub + two-level TLD' => ['org.blog.acme.com.br'],
    'full url' => ['https://org.blog.acme.com.br/a?b=c'],
]);

test('it returns the original content for x when the regex engine bails out', function (string $content) {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize($content, Platform::X))->toBe($content);
})->with([
    'catastrophic backtracking' => [str_repeat('a.a', 5000)],
    'invalid utf-8' => ["acme.com \xC3\x28 broken"],
]);

test('it defuses the same content the same way when run again', function () {
    $sanitizer = new ContentSanitizer;
    $once = $sanitizer->sanitize('See https://acme.com/x now', Platform::X);

    expect($sanitizer->sanitize($once, Platform::X))->toBe($once);
});

test('it defuses a file name whose extension is a delegated tld for x', function (string $input, string $expected) {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize("See {$input} here", Platform::X))->toBe("See {$expected} here");
})->with([
    'zip is a delegated tld' => ['backup.zip', 'backup(.)zip'],
    'mov is a delegated tld' => ['clip.mov', 'clip(.)mov'],
    'md is a delegated tld' => ['README.md', 'README(.)md'],
]);

test('it defuses a url with an explicit scheme even when the tld is unknown', function () {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize('See https://intranet.acme.internal/x', Platform::X))
        ->toBe('See intranet(.)acme(.)internal/x');
});

test('it leaves a bare host with an unknown tld untouched for x', function () {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize('See intranet.acme.internal here', Platform::X))
        ->toBe('See intranet.acme.internal here');
});

test('it measures a platform limit against the rendered text, not the markup', function () {
    $sanitizer = new ContentSanitizer;
    $content = implode(' & ', array_fill(0, 900, 'a'));

    expect($sanitizer->displayText($content, Platform::Telegram))->toBe($content)
        ->and(Platform::Telegram->contentOverflow($sanitizer->displayText($content, Platform::Telegram)))
        ->toBe(0);
});

test('it counts the defused form for x because those characters are visible', function () {
    $sanitizer = new ContentSanitizer;
    config()->set('trypost.platforms.x.defuse_links', true);

    expect($sanitizer->displayText('acme.com', Platform::X))->toBe('acme(.)com');
});

test('it defuses a url that carries userinfo for x', function () {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize('See https://user@acme.com/x now', Platform::X))
        ->toBe('See acme(.)com/x now');
});

test('it still leaves a plain email address untouched for x', function () {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize('Email contact@acme.com.br today', Platform::X))
        ->toBe('Email contact@acme.com.br today');
});

test('it defuses an internationalised host for x', function (string $input, string $expected) {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize("See {$input} now", Platform::X))->toBe("See {$expected} now");
})->with([
    'unicode label, ascii tld' => ['café.com', 'café(.)com'],
    'unicode subdomain' => ['blog.café.com.br', 'blog(.)café(.)com(.)br'],
    'unicode tld' => ['пример.рф', 'пример(.)рф'],
    'punycode host' => ['acme.xn--p1ai', 'acme(.)xn--p1ai'],
    'unicode with scheme' => ['https://café.com/menü', 'café(.)com/menü'],
]);

test('it defuses tlds written in scripts that use combining marks for x', function (string $input, string $expected) {
    $sanitizer = new ContentSanitizer;

    expect($sanitizer->sanitize("See {$input} now", Platform::X))->toBe("See {$expected} now");
})->with([
    'devanagari' => ['उदाहरण.भारत', 'उदाहरण(.)भारत'],
    'sinhala' => ['උදාහරණ.ලංකා', 'උදාහරණ(.)ලංකා'],
    'tamil' => ['உதாரணம்.இந்தியா', 'உதாரணம்(.)இந்தியா'],
]);

test('it does not decode entities twice when the platform already resolved them', function (Platform $platform) {
    $sanitizer = new ContentSanitizer;
    $content = 'Tom &amp;amp; Jerry';

    expect($sanitizer->displayText($content, $platform))
        ->toBe($sanitizer->sanitize($content, $platform));
})->with([
    'x' => [Platform::X],
    'linkedin' => [Platform::LinkedIn],
    'instagram' => [Platform::Instagram],
]);

test('it resolves markup only for the platforms whose sanitized form carries it', function () {
    $sanitizer = new ContentSanitizer;
    $content = 'Tom & Jerry';

    // Telegram escapes the ampersand for parse_mode=HTML; the reader still sees one.
    expect($sanitizer->sanitize($content, Platform::Telegram))->toContain('&amp;')
        ->and($sanitizer->displayText($content, Platform::Telegram))->toBe($content);
});

test('it strips mastodon markup for length without decoding its entities twice', function () {
    $sanitizer = new ContentSanitizer;
    $content = '<p>Tom &amp;amp; <strong>Jerry</strong></p>';

    expect($sanitizer->displayText($content, Platform::Mastodon))->toBe('Tom &amp; Jerry');
});
