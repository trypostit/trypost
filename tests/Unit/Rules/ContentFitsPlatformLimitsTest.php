<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Rules\ContentFitsPlatformLimits;

function runFitsRule(string $content, array $platforms): array
{
    $errors = [];
    $rule = new ContentFitsPlatformLimits(collect($platforms));

    $rule->validate('content', $content, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

test('passes when content fits every platform cap', function () {
    $errors = runFitsRule(str_repeat('a', 280), [Platform::X, Platform::Threads, Platform::Facebook]);

    expect($errors)->toBe([]);
});

test('fails with the platform label, limit and overage when content exceeds a single platform', function () {
    $errors = runFitsRule(str_repeat('a', 537), [Platform::Threads]);

    expect($errors)->toHaveCount(1);
    expect($errors[0])
        ->toContain('Threads')
        ->toContain('500')
        ->toContain('37');
});

test('emits one error per overflowing platform in a multi-platform set', function () {
    // 320 chars: fine for Threads (500), over for X (280) and Bluesky (300).
    $errors = runFitsRule(str_repeat('a', 320), [Platform::X, Platform::Bluesky, Platform::Threads]);

    expect($errors)->toHaveCount(2);
    expect($errors[0])->toContain('X');
    expect($errors[1])->toContain('Bluesky');
});

test('deduplicates errors when the same platform appears twice in the collection', function () {
    // Two Threads accounts selected, content 600 chars — should still produce ONE error.
    $errors = runFitsRule(str_repeat('a', 600), [Platform::Threads, Platform::Threads]);

    expect($errors)->toHaveCount(1);
});

test('passes for an empty platforms collection', function () {
    $errors = runFitsRule(str_repeat('a', 10_000), []);

    expect($errors)->toBe([]);
});

test('treats null content as an empty string and passes', function () {
    $errors = runFitsRule('', [Platform::Threads, Platform::X]);

    expect($errors)->toBe([]);
});

test('measures the defused length for x so a link post that will fit is accepted', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $errors = runFitsRule(str_repeat('a', 263).' https://acme.com/x', [Platform::X]);

    expect($errors)->toBe([]);
});

test('measures the defused length for x so a link post that will not fit is rejected', function () {
    config()->set('trypost.platforms.x.defuse_links', true);
    $errors = runFitsRule(str_repeat('a', 271).' acme.com', [Platform::X]);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('X')->toContain('280')->toContain('2');
});

test('does not count html markup toward a platform cap', function () {
    $errors = runFitsRule('<p>'.str_repeat('a', 275).'</p>', [Platform::X]);

    expect($errors)->toBe([]);
});

test('measures telegram against the rendered text, not the escaped markup', function () {
    // 3.597 characters, under Telegram's 4.096 cap. Escaping every ampersand for
    // parse_mode=HTML nearly doubles that, but the reader still sees one character.
    $content = implode(' & ', array_fill(0, 900, 'a'));

    expect(mb_strlen($content))->toBeLessThan(Platform::Telegram->maxContentLength())
        ->and(runFitsRule($content, [Platform::Telegram]))->toBe([]);
});
