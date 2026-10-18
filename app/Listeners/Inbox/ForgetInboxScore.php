<?php

declare(strict_types=1);

namespace App\Listeners\Inbox;

use App\Actions\Inbox\CalculateInboxScore;
use App\Events\InboxUpdated;

class ForgetInboxScore
{
    public function handle(InboxUpdated $event): void
    {
        CalculateInboxScore::forget($event->workspaceId);
    }
}
