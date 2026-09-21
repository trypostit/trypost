<?php

declare(strict_types=1);

use App\Enums\GoogleBusiness\LocalPostState;

test('local post state mirrors the official v4 values', function () {
    expect(LocalPostState::cases())->toHaveCount(6)
        ->and(LocalPostState::Unspecified->value)->toBe('LOCAL_POST_STATE_UNSPECIFIED')
        ->and(LocalPostState::Rejected->value)->toBe('REJECTED')
        ->and(LocalPostState::Live->value)->toBe('LIVE')
        ->and(LocalPostState::Processing->value)->toBe('PROCESSING')
        ->and(LocalPostState::Scheduled->value)->toBe('SCHEDULED')
        ->and(LocalPostState::Recurring->value)->toBe('RECURRING');
});

test('fromApi treats a missing or unknown state as still processing', function (mixed $value, LocalPostState $expected) {
    expect(LocalPostState::fromApi($value))->toBe($expected);
})->with([
    [null, LocalPostState::Processing],
    ['', LocalPostState::Processing],
    ['PROCESSING', LocalPostState::Processing],
    ['SCHEDULED', LocalPostState::Scheduled],
    ['LIVE', LocalPostState::Live],
    ['RECURRING', LocalPostState::Recurring],
    ['REJECTED', LocalPostState::Rejected],
    ['LOCAL_POST_STATE_UNSPECIFIED', LocalPostState::Unspecified],
    ['UNKNOWN', LocalPostState::Processing],
]);

test('pending review states keep the jpeg until google finishes fetching', function (LocalPostState $state, bool $pending) {
    expect($state->isPendingReview())->toBe($pending);
})->with([
    [LocalPostState::Processing, true],
    [LocalPostState::Scheduled, true],
    [LocalPostState::Live, false],
    [LocalPostState::Recurring, false],
    [LocalPostState::Rejected, false],
    [LocalPostState::Unspecified, false],
]);

test('live states are visible in search', function (LocalPostState $state, bool $live) {
    expect($state->isLive())->toBe($live);
})->with([
    [LocalPostState::Live, true],
    [LocalPostState::Recurring, true],
    [LocalPostState::Processing, false],
    [LocalPostState::Scheduled, false],
    [LocalPostState::Rejected, false],
    [LocalPostState::Unspecified, false],
]);

test('only rejected is a policy refusal', function () {
    expect(LocalPostState::Rejected->isRejected())->toBeTrue()
        ->and(LocalPostState::Live->isRejected())->toBeFalse()
        ->and(LocalPostState::Processing->isRejected())->toBeFalse();
});
