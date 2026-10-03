<?php

declare(strict_types=1);

namespace App\Enums\Notification;

enum Type: string
{
    case PostPublished = 'post_published';
    case PostFailed = 'post_failed';
    case AccountDisconnected = 'account_disconnected';
    case PostAtRisk = 'post_at_risk';
    case PostNoteAdded = 'post_note_added';
    case Collaboration = 'collaboration';
}
