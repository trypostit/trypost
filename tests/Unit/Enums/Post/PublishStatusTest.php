<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;

test('a finished publication settles the post', function (PublishStatus $status, bool $finished) {
    expect($status->isFinished())->toBe($finished);
})->with([
    [PublishStatus::Published, true],
    [PublishStatus::Failed, true],
    [PublishStatus::Rejected, true],
    [PublishStatus::PendingReview, false],
    [PublishStatus::Pending, false],
    [PublishStatus::Publishing, false],
    [PublishStatus::Retrying, false],
]);

test('a closed publication must not be published again', function (PublishStatus $status, bool $closed) {
    expect($status->isClosed())->toBe($closed);
})->with([
    [PublishStatus::Published, true],
    [PublishStatus::Failed, true],
    [PublishStatus::Rejected, true],
    [PublishStatus::PendingReview, true],
    [PublishStatus::Pending, false],
    [PublishStatus::Publishing, false],
    [PublishStatus::Retrying, false],
]);

test('a pending, publishing or retrying publication is in flight', function (PublishStatus $status, bool $inFlight) {
    expect($status->isInFlight())->toBe($inFlight);
})->with([
    [PublishStatus::Pending, true],
    [PublishStatus::Publishing, true],
    [PublishStatus::Retrying, true],
    [PublishStatus::PendingReview, false],
    [PublishStatus::Published, false],
    [PublishStatus::Failed, false],
    [PublishStatus::Rejected, false],
]);
