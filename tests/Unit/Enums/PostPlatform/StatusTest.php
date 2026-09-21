<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status;

test('a finished target counts toward settling the parent post', function (Status $status, bool $finished) {
    expect($status->isFinished())->toBe($finished);
})->with([
    [Status::Published, true],
    [Status::Failed, true],
    [Status::Rejected, true],
    [Status::PendingReview, false],
    [Status::Pending, false],
    [Status::Publishing, false],
    [Status::Retrying, false],
]);

test('a closed target must not be published again', function (Status $status, bool $closed) {
    expect($status->isClosed())->toBe($closed);
})->with([
    [Status::Published, true],
    [Status::Failed, true],
    [Status::Rejected, true],
    [Status::PendingReview, true],
    [Status::Pending, false],
    [Status::Publishing, false],
    [Status::Retrying, false],
]);
