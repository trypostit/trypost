<?php

declare(strict_types=1);

use App\Enums\GoogleBusiness\TopicType;

test('topic type matches the authorable local post values', function () {
    expect(TopicType::values())->toBe(['STANDARD', 'EVENT', 'OFFER']);
});

test('fromMeta defaults a missing or unknown topic to standard', function (mixed $value, TopicType $expected) {
    expect(TopicType::fromMeta($value))->toBe($expected);
})->with([
    [null, TopicType::Standard],
    ['', TopicType::Standard],
    ['STANDARD', TopicType::Standard],
    ['EVENT', TopicType::Event],
    ['OFFER', TopicType::Offer],
    ['ALERT', TopicType::Standard],
    ['PRODUCT', TopicType::Standard],
]);

test('event and offer require an event object', function (TopicType $type, bool $requiresEvent) {
    expect($type->requiresEvent())->toBe($requiresEvent);
})->with([
    [TopicType::Event, true],
    [TopicType::Offer, true],
    [TopicType::Standard, false],
]);

test('offer is the only topic that cannot carry a call to action', function () {
    expect(TopicType::Standard->allowsCallToAction())->toBeTrue()
        ->and(TopicType::Event->allowsCallToAction())->toBeTrue()
        ->and(TopicType::Offer->allowsCallToAction())->toBeFalse();
});
