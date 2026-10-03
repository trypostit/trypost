<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum ApprovalDecision: string
{
    case Approved = 'approved';
    case Rejected = 'rejected';
}
