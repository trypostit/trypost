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

    public const self DEFAULT = self::Pending;

    /** Published, failed, or rejected — the post can be settled. */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Published, self::Failed, self::Rejected => true,
            default => false,
        };
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
