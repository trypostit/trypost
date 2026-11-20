<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum PublishStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Retrying = 'retrying';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Deleted = 'deleted';

    public const self DEFAULT = self::Pending;

    /** Published, failed, rejected or deleted on the network — the post can be settled. */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Published, self::Failed, self::Rejected, self::Deleted => true,
            default => false,
        };
    }

    /** The network published the post, deleted there since or not. */
    public function wentOut(): bool
    {
        return match ($this) {
            self::Published, self::Deleted => true,
            default => false,
        };
    }

    /** @return list<self> */
    public static function wentOutCases(): array
    {
        return collect(self::cases())->filter(fn (self $status): bool => $status->wentOut())->values()->all();
    }

    /** The publish job must not run again. Pending review waits on reconcile. */
    public function isClosed(): bool
    {
        return $this->isFinished() || $this === self::PendingReview;
    }

    /** Still to be sent or being sent: pending, publishing or retrying. */
    public function isInFlight(): bool
    {
        return ! $this->isClosed();
    }
}
