<?php

declare(strict_types=1);

namespace App\Console\Commands\Inbox;

use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Media\DeleteOwnedMedia;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\InboxPendingMessage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class PruneInbox extends Command
{
    protected $signature = 'inbox:prune';

    protected $description = 'Delete inbox threads past the retention, the conversations left empty and comments whose post never came';

    /**
     * A thread goes as a whole, once its newest message is past the
     * retention, so a reply never outlives the comment it answers.
     */
    public function handle(): int
    {
        $cutoff = now()->subMonths((int) config('trypost.inbox.retention_months'));
        $touched = [];

        do {
            $messages = InboxMessage::query()
                ->inThreadsOlderThan($cutoff)
                ->limit(500)
                ->get(['id', 'root_id', 'inbox_conversation_id']);
            $roots = $messages->map(fn (InboxMessage $message): string => $message->root_id ?? $message->id)->unique()->values();

            DB::transaction(function () use ($roots): void {
                DeleteOwnedMedia::forInboxMessages(InboxMessage::query()
                    ->where(fn (Builder $tree) => $tree->whereIn('root_id', $roots)->orWhereIn('id', $roots))
                    ->pluck('id')
                    ->all());

                InboxMessage::query()->whereIn('root_id', $roots)->delete();
                InboxMessage::query()->whereKey($roots)->delete();
            });
            $touched = array_unique([...$touched, ...$messages->pluck('inbox_conversation_id')->all()]);
        } while ($messages->isNotEmpty());

        foreach (array_chunk($touched, 200) as $ids) {
            DB::transaction(function () use ($ids): void {
                $empty = InboxConversation::query()->whereKey($ids)->doesntHave('messages')->pluck('id')->all();

                DeleteOwnedMedia::forInboxConversations($empty);

                InboxConversation::query()->whereKey($empty)->doesntHave('messages')->delete();
            });

            InboxConversation::query()->whereKey($ids)->get()->each(
                fn (InboxConversation $conversation) => RecountInboxConversation::execute($conversation),
            );
        }

        InboxPendingMessage::query()->where('created_at', '<', now()->subDays(InboxPendingMessage::KEEP_DAYS))->delete();

        return self::SUCCESS;
    }
}
