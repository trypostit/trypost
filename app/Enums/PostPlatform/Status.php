<?php

declare(strict_types=1);

namespace App\Enums\PostPlatform;

enum Status: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Submitted = 'submitted';
    case PendingReview = 'pending_review';
    case Retrying = 'retrying';
    case Published = 'published';
    case Failed = 'failed';
    case Rejected = 'rejected';
}
