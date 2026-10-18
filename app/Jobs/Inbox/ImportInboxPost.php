<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Actions\Inbox\ImportInboxConversationPost;
use App\Actions\Inbox\UpsertInboxMessages;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Exceptions\Inbox\InboxPostUnavailableException;
use App\Models\InboxConversation;
use App\Models\InboxPendingMessage;
use App\Models\SocialAccount;
use App\Support\Inbox\RedactedError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Imports the post waiting comments are under, then stores them under it.
 * A post the network does not return yet is tried again with backoff; after
 * MAX_ATTEMPTS its comments are dropped and the failure reported once.
 */
class ImportInboxPost implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const int MAX_ATTEMPTS = 5;

    /** @var list<int> seconds before each new attempt */
    public const array BACKOFF = [60, 300, 1800, 7200];

    private const int MAX_ROUNDS = 3;

    public int $tries = self::MAX_ATTEMPTS;

    public int $timeout = 120;

    public int $uniqueFor = 43200;

    public function __construct(public string $socialAccountId, public string $platformPostId)
    {
        $this->onQueue('inbox');
    }

    public function uniqueId(): string
    {
        return "{$this->socialAccountId}:".InboxConversation::hashOf($this->platformPostId);
    }

    public function handle(): void
    {
        $account = SocialAccount::query()->find($this->socialAccountId);
        $pending = $this->pending();

        if (! $account || $pending->isEmpty()) {
            return;
        }

        try {
            ImportInboxConversationPost::execute($account, self::subjectOf($pending));
        } catch (InboxPostUnavailableException $exception) {
            if ($exception->dismissed || $this->attempts() >= self::MAX_ATTEMPTS) {
                $this->giveUp($pending, $exception);

                return;
            }

            $this->release(self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);

            return;
        }

        for ($round = 0; $round < self::MAX_ROUNDS && $pending->isNotEmpty(); $round++) {
            InboxPendingMessage::query()->whereKey($pending->modelKeys())->delete();
            UpsertInboxMessages::execute($account, $pending->map(fn (InboxPendingMessage $row): IncomingMessage => $row->message())->values()->all());
            $pending = $this->pending()->whereNotIn('id', $pending->modelKeys())->values();
        }
    }

    /** @return Collection<int, InboxPendingMessage> */
    private function pending(): Collection
    {
        return InboxPendingMessage::query()
            ->forPost($this->socialAccountId, $this->platformPostId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * The read that told the most about the post: the publication a poll
     * listed, else one that carries the post's time.
     *
     * @param  Collection<int, InboxPendingMessage>  $pending
     */
    private static function subjectOf(Collection $pending): IncomingSubject
    {
        $subjects = $pending->map(fn (InboxPendingMessage $row): IncomingSubject => $row->message()->subject);

        return $subjects->first(fn (IncomingSubject $subject): bool => $subject->publication !== null)
            ?? $subjects->first(fn (IncomingSubject $subject): bool => $subject->publishedAt !== null)
            ?? $subjects->first();
    }

    /** @param Collection<int, InboxPendingMessage> $pending */
    private function giveUp(Collection $pending, InboxPostUnavailableException $exception): void
    {
        InboxPendingMessage::query()->forPost($this->socialAccountId, $this->platformPostId)->delete();

        if (! $exception->dismissed) {
            RedactedError::report(new InboxPostUnavailableException(
                "Dropped {$pending->count()} inbox comments: their post {$this->platformPostId} could not be imported after {$this->attempts()} attempts. {$exception->getMessage()}",
                previous: $exception,
            ));
        }
    }
}
