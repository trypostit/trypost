<?php

declare(strict_types=1);

namespace App\Enums\Post;

use LogicException;

enum ScheduleAction: string
{
    case Draft = 'draft';
    case PublishNow = 'publish_now';
    case QueueNext = 'queue_next';
    case QueueTop = 'queue_top';
    case Reschedule = 'reschedule';

    /**
     * What UpdatePost receives for an action that only changes the post's status or queue position.
     * Reschedule carries a time and goes through ReschedulePost instead.
     *
     * @return array{status: string, queue?: string}
     */
    public function postChanges(): array
    {
        return match ($this) {
            self::Draft => ['status' => Status::Draft->value],
            self::PublishNow => ['status' => Status::Publishing->value],
            self::QueueNext => ['status' => Status::Scheduled->value, 'queue' => QueuePosition::Next->value],
            self::QueueTop => ['status' => Status::Scheduled->value, 'queue' => QueuePosition::Top->value],
            self::Reschedule => throw new LogicException('Reschedule goes through ReschedulePost.'),
        };
    }

    /**
     * What the toast says once the action puts the post in the queue; null for
     * an action that does not. The top of the queue is a move, not an addition:
     * "Move to top" only reaches posts already queued.
     */
    public function queuedMessage(): ?string
    {
        return match ($this) {
            self::QueueNext => trans_choice('posts.composer.queue.added', 1, ['count' => 1]),
            self::QueueTop => __('posts.composer.queue.moved_to_top'),
            self::Draft, self::PublishNow, self::Reschedule => null,
        };
    }
}
