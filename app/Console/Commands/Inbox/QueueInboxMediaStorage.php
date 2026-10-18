<?php

declare(strict_types=1);

namespace App\Console\Commands\Inbox;

use App\Jobs\Inbox\StoreInboxMediaJob;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Support\Inbox\InboxMediaSlots;
use Illuminate\Console\Command;

class QueueInboxMediaStorage extends Command
{
    protected $signature = 'inbox:store-media';

    protected $description = 'Queue the copy of the inbox files still linked on their networks';

    public function handle(): int
    {
        $queued = 0;

        InboxMessage::query()->lazyById(500)->each(function (InboxMessage $message) use (&$queued): void {
            if (InboxMediaSlots::pending($message) !== []) {
                StoreInboxMediaJob::dispatch(StoreInboxMediaJob::MESSAGE, $message->id);
                $queued++;
            }
        });

        InboxConversation::query()->lazyById(500)->each(function (InboxConversation $conversation) use (&$queued): void {
            if (InboxMediaSlots::pending($conversation) !== []) {
                StoreInboxMediaJob::dispatch(StoreInboxMediaJob::CONVERSATION, $conversation->id);
                $queued++;
            }
        });

        $this->info("Queued {$queued} inbox rows.");

        return self::SUCCESS;
    }
}
