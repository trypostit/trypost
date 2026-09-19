<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\Webhook\EventType;

test('every webhook event has the expected value', function (EventType $event, string $value) {
    expect($event->value)->toBe($value);
})->with([
    [EventType::PostCreated, 'post.created'],
    [EventType::PostScheduled, 'post.scheduled'],
    [EventType::PostUnscheduled, 'post.unscheduled'],
    [EventType::PostPublished, 'post.published'],
    [EventType::PostPartiallyPublished, 'post.partially_published'],
    [EventType::PostFailed, 'post.failed'],
    [EventType::PostDeleted, 'post.deleted'],
]);

test('fromPostStatus maps publishable statuses and ignores the rest', function (PostStatus $status, ?EventType $event, ?PostStatus $previous = null) {
    expect(EventType::fromPostStatus($status, $previous))->toBe($event);
})->with([
    [PostStatus::Scheduled, EventType::PostScheduled],
    [PostStatus::Published, EventType::PostPublished],
    [PostStatus::PartiallyPublished, EventType::PostPartiallyPublished],
    [PostStatus::Failed, EventType::PostFailed],
    [PostStatus::Draft, null],
    [PostStatus::Draft, EventType::PostUnscheduled, PostStatus::Scheduled],
    [PostStatus::Draft, null, PostStatus::Failed],
    [PostStatus::Draft, null, PostStatus::Publishing],
    [PostStatus::Draft, null, PostStatus::Published],
    [PostStatus::Publishing, null],
]);
