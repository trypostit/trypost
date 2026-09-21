<?php

declare(strict_types=1);

use App\Enums\GoogleBusiness\CtaAction;

test('cta action matches the buttons we persist', function () {
    expect(CtaAction::values())->toBe([
        'NONE',
        'BOOK',
        'ORDER',
        'SHOP',
        'LEARN_MORE',
        'SIGN_UP',
        'CALL',
    ]);
});

test('fromMeta defaults a missing or unknown action to none', function (mixed $value, CtaAction $expected) {
    expect(CtaAction::fromMeta($value))->toBe($expected);
})->with([
    [null, CtaAction::None],
    ['', CtaAction::None],
    ['NONE', CtaAction::None],
    ['BOOK', CtaAction::Book],
    ['CALL', CtaAction::Call],
    ['GET_OFFER', CtaAction::None],
]);

test('url is required except for none and call', function (CtaAction $action, bool $requiresUrl) {
    expect($action->requiresUrl())->toBe($requiresUrl);
})->with([
    [CtaAction::None, false],
    [CtaAction::Call, false],
    [CtaAction::Book, true],
    [CtaAction::Order, true],
    [CtaAction::Shop, true],
    [CtaAction::LearnMore, true],
    [CtaAction::SignUp, true],
]);
