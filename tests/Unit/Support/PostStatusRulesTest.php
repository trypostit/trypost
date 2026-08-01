<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Support\PostStatusRules;

test('blocks editing for terminal statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect(PostStatusRules::blocksEditing($post))->toBeTrue();
})->with([
    PostStatus::Publishing,
    PostStatus::Published,
    PostStatus::PartiallyPublished,
    PostStatus::Failed,
]);

test('allows editing for non terminal statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect(PostStatusRules::blocksEditing($post))->toBeFalse();
})->with([
    PostStatus::Draft,
    PostStatus::Scheduled,
]);

test('blocks deletion for published statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect(PostStatusRules::blocksDeletion($post))->toBeTrue();
})->with([
    PostStatus::Publishing,
    PostStatus::Published,
    PostStatus::PartiallyPublished,
]);

test('allows deletion for draft, scheduled and failed statuses', function (PostStatus $status) {
    $post = Post::factory()->make(['status' => $status]);

    expect(PostStatusRules::blocksDeletion($post))->toBeFalse();
})->with([
    PostStatus::Draft,
    PostStatus::Scheduled,
    PostStatus::Failed,
]);

test('requires explicit schedule when status is scheduled and post has no usable schedule', function (?string $scheduledAt, bool $expected) {
    $post = Post::factory()->make([
        'scheduled_at' => $scheduledAt,
    ]);

    expect(PostStatusRules::requiresExplicitSchedule($post, PostStatus::Scheduled->value))->toBe($expected);
})->with([
    'missing schedule' => [null, true],
    'past schedule' => [now()->subHour()->toDateTimeString(), true],
    'future schedule' => [now()->addDay()->toDateTimeString(), false],
]);

test('does not require explicit schedule for non scheduled statuses', function () {
    $post = Post::factory()->make(['scheduled_at' => null]);

    expect(PostStatusRules::requiresExplicitSchedule($post, PostStatus::Draft->value))->toBeFalse()
        ->and(PostStatusRules::requiresExplicitSchedule(null, PostStatus::Scheduled->value))->toBeTrue();
});
