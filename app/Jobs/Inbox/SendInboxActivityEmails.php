<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Actions\Inbox\NotifyInboxActivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendInboxActivityEmails implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('inbox');
    }

    public function handle(): void
    {
        NotifyInboxActivity::execute();
    }
}
