<?php

declare(strict_types=1);

use App\Enums\Post\Status;

test('post status has correct values', function () {
    expect(Status::Draft->value)->toBe('draft');
    expect(Status::Scheduled->value)->toBe('scheduled');
    expect(Status::Publishing->value)->toBe('publishing');
    expect(Status::Published->value)->toBe('published');
    expect(Status::PartiallyPublished->value)->toBe('partially_published');
    expect(Status::Failed->value)->toBe('failed');
});

test('post status has labels', function () {
    expect(Status::Draft->label())->toBe('Draft');
    expect(Status::Scheduled->label())->toBe('Scheduled');
    expect(Status::Publishing->label())->toBe('Publishing');
    expect(Status::Published->label())->toBe('Published');
    expect(Status::PartiallyPublished->label())->toBe('Partially Published');
    expect(Status::Failed->label())->toBe('Failed');
});

test('settled statuses are the ones finalize must not rewrite', function () {
    expect(Status::Draft->isSettled())->toBeFalse();
    expect(Status::Scheduled->isSettled())->toBeFalse();
    expect(Status::Publishing->isSettled())->toBeFalse();
    expect(Status::Published->isSettled())->toBeTrue();
    expect(Status::PartiallyPublished->isSettled())->toBeTrue();
    expect(Status::Failed->isSettled())->toBeTrue();
});

test('post status has colors', function () {
    expect(Status::Draft->color())->toBe('gray');
    expect(Status::Scheduled->color())->toBe('blue');
    expect(Status::Publishing->color())->toBe('yellow');
    expect(Status::Published->color())->toBe('green');
    expect(Status::PartiallyPublished->color())->toBe('orange');
    expect(Status::Failed->color())->toBe('red');
});
