<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Support\FirstComment;
use App\Support\ThreadReplies;

test('no network offers both a first comment and thread replies', function () {
    foreach (Platform::cases() as $platform) {
        expect(FirstComment::supports($platform) && ThreadReplies::supports($platform))->toBeFalse();
    }
});

test('a first comment is offered on linkedin profiles and pages only', function () {
    $supported = collect(Platform::cases())->filter(FirstComment::supports(...))->values()->all();

    expect($supported)->toBe([Platform::LinkedIn, Platform::LinkedInPage])
        ->and(FirstComment::maxLength(Platform::LinkedIn))->toBe(1250)
        ->and(FirstComment::maxLength(Platform::X))->toBeNull();
});

test('a first comment is available to an account only on a supported network holding the scopes it needs', function () {
    $linkedin = SocialAccount::factory()->linkedin()->make(['scopes' => []]);
    $x = SocialAccount::factory()->x()->make();

    expect(FirstComment::availableFor($linkedin))->toBeTrue()
        ->and(FirstComment::limitsFor($linkedin))->toBe(['maxLength' => 1250])
        ->and(FirstComment::availableFor($x))->toBeFalse()
        ->and(FirstComment::limitsFor($x))->toBeNull();
});

test('a blank first comment normalizes to nothing and its text is trimmed', function (mixed $input, ?array $expected) {
    expect(FirstComment::normalize($input))->toBe($expected);
})->with([
    'spaces' => [['text' => '   '], null],
    'null text' => [['text' => null], null],
    'not an array' => ['Link in the comments', null],
    'trimmed' => [['text' => '  Link below  '], ['text' => 'Link below']],
]);

test('the text of a first comment is read trimmed from the meta', function () {
    expect(FirstComment::text(['first_comment' => ['text' => ' hi ']]))->toBe('hi')
        ->and(FirstComment::text(['first_comment' => ['text' => '  ']]))->toBeNull()
        ->and(FirstComment::text([]))->toBeNull();
});

test('a first comment longer than the network allows is a violation counted in code points', function () {
    expect(FirstComment::violation(Platform::LinkedIn, ['first_comment' => ['text' => str_repeat('🐧', 1250)]]))->toBeNull()
        ->and(FirstComment::violation(Platform::LinkedIn, ['first_comment' => ['text' => str_repeat('a', 1251)]]))->toBe([
            'first_comment.text',
            __('posts.form.first_comment.too_long', ['max' => 1250]),
        ]);
});
