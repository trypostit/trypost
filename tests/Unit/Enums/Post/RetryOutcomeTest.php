<?php

declare(strict_types=1);

use App\Enums\Post\RetryOutcome;

test('every outcome names the field and the message the backoffice shows', function (RetryOutcome $outcome, string $field, string $message) {
    expect($outcome->errorField())->toBe($field)
        ->and($outcome->message())->toBe($message);
})->with([
    'queued' => [RetryOutcome::Queued, 'post', 'A publish attempt was queued.'],
    'not retryable' => [RetryOutcome::NotRetryable, 'post', 'Only a failed post with a channel can be retried.'],
    'comment unconfirmed' => [RetryOutcome::CommentUnconfirmed, 'post', fn (): string => __('posts.errors.instagram.comment_unconfirmed')],
    'needs instagram confirmation' => [RetryOutcome::NeedsInstagramConfirmation, 'instagram_comment_checked', 'Check on Instagram that the first comment is missing, then confirm.'],
    'owner blocked' => [RetryOutcome::OwnerBlocked, 'post', 'The account owner is blocked: unblock them to retry this post.'],
    'changed' => [RetryOutcome::Changed, 'post', 'The post changed meanwhile; nothing was retried.'],
]);
