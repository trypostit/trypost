<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum ScheduleAction: string
{
    case Draft = 'draft';
    case PublishNow = 'publish_now';
    case QueueNext = 'queue_next';
    case QueueTop = 'queue_top';
    case Reschedule = 'reschedule';

    /**
     * What UpdatePost receives for an action that only changes the post's status or queue position.
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
        };
    }
}
