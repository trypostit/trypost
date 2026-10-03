<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum Action: string
{
    case Finalized = 'finalized';
    case Publishing = 'publishing';
    case Scheduled = 'scheduled';
    case PendingApproval = 'pending_approval';
}
