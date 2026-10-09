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

test('a failed or rejected publication is a failure', function (PublishStatus $status, bool $failure) {
    expect($status->isFailure())->toBe($failure);
})->with([
    [PublishStatus::Failed, true],
    [PublishStatus::Rejected, true],
    [PublishStatus::Published, false],
    [PublishStatus::PendingReview, false],
    [PublishStatus::Pending, false],
    [PublishStatus::Publishing, false],
    [PublishStatus::Retrying, false],
]);
