<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Actions\Inbox\StoreInboxMedia;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Copies one inbox row's network files into storage, on the media imports
 * queue. An unreachable file is tried again after a delay; past the last
 * attempt, or when the job fails, what is left is marked unstored so it is
 * never queued again.
 */
class StoreInboxMediaJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const string MESSAGE = 'message';

    public const string CONVERSATION = 'conversation';

    private const array RETRY_DELAYS = [60, 300];

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public bool $failOnTimeout = true;

    public int $timeout;

    public function __construct(public string $target, public string $id)
    {
        $this->onQueue(ImportRemoteMedia::QUEUE);
        $this->timeout = ImportRemoteMedia::timeoutSeconds();
    }

    public function uniqueId(): string
    {
        return "{$this->target}:{$this->id}";
    }

    public function handle(): void
    {
        $model = $this->model();

        if (! $model) {
            return;
        }

        if (StoreInboxMedia::execute($model, $this->attempts() >= $this->tries, $this->timeout)) {
            $this->release(data_get(self::RETRY_DELAYS, $this->attempts() - 1, 300));
        }
    }

    public function failed(?Throwable $exception): void
    {
        rescue(function (): void {
            $model = $this->model();

            if ($model) {
                StoreInboxMedia::giveUp($model);
            }
        }, report: false);
    }

    private function model(): InboxMessage|InboxConversation|null
    {
        return $this->target === self::CONVERSATION
            ? InboxConversation::query()->find($this->id)
            : InboxMessage::query()->find($this->id);
    }
}
