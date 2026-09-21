<?php

declare(strict_types=1);

namespace App\Enums\PostPlatform;

enum Status: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Retrying = 'retrying';
    case PendingReview = 'pending_review';
    case Published = 'published';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /** Published, failed, or rejected — counts toward settling the parent post. */
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
}
