<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Enums\Notification\Type;
use App\Enums\SocialAccount\Platform;
use App\Jobs\SendNotification;
use App\Mail\InboxActivity;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class NotifyInboxActivity
{
    public const int WINDOW_HOURS = 24;

    public static function execute(): int
    {
        $platforms = Platform::inboxValues();

        if ($platforms === []) {
            return 0;
        }

        $since = now()->subHours(self::WINDOW_HOURS);
        $notified = 0;
        /** @var array<string, SupportCollection<int, User>> $approvers */
        $approvers = [];

        InboxConversation::query()
            ->whereIn('kind', [ConversationKind::Comment->value, ConversationKind::Review->value])
            ->where('open_count', '>', 0)
            ->whereHas('socialAccount', fn (Builder $accounts) => $accounts->whereIn('platform', $platforms))
            ->where(fn (Builder $query) => $query
                ->where('kind', ConversationKind::Review->value)
                ->orWhereHas('post', fn (Builder $post) => $post->where('published_at', '>=', $since)))
            ->whereHas('messages', fn (Builder $messages) => self::recent($messages, $since)
                ->where(fn (Builder $arrival) => $arrival
                    ->whereNull('inbox_conversations.notified_at')
                    ->orWhereColumn('inbox_messages.created_at', '>', 'inbox_conversations.notified_at')))
            ->with('workspace.account')
            ->chunkById(100, function (Collection $conversations) use ($since, &$notified, &$approvers): void {
                foreach ($conversations as $conversation) {
                    if (! $conversation->workspace?->account?->hasAppAccess()) {
                        continue;
                    }

                    $people = self::claim($conversation, $since);

                    if ($people === 0) {
                        continue;
                    }

                    $approvers[$conversation->workspace_id] ??= $conversation->workspace->approvers();

                    foreach ($approvers[$conversation->workspace_id] as $recipient) {
                        SendNotification::dispatch(
                            user: $recipient,
                            type: Type::InboxActivity,
                            mailable: new InboxActivity($conversation->id, $people, $recipient),
                        );
                    }

                    $notified++;
                }
            });

        return $notified;
    }

    /**
     * Counts the people behind the messages that arrived since the last email
     * and stamps notified_at with the arrival of the newest one counted, under
     * the conversation's row lock.
     */
    private static function claim(InboxConversation $conversation, CarbonInterface $since): int
    {
        return DB::transaction(function () use ($conversation, $since): int {
            $locked = InboxConversation::query()->whereKey($conversation->id)->lockForUpdate()->first();

            if (! $locked) {
                return 0;
            }

            $messages = self::recent($locked->messages()->getQuery(), $since)
                ->when(filled($locked->notified_at), fn (Builder $query) => $query->where('inbox_messages.created_at', '>', $locked->notified_at))
                ->get(['id', 'author_platform_id', 'created_at']);

            $people = $messages
                ->map(fn (InboxMessage $message): string => $message->author_platform_id ?? $message->id)
                ->unique()
                ->count();

            if ($people > 0) {
                InboxConversation::query()->whereKey($locked->id)->toBase()->update(['notified_at' => $messages->max('created_at')]);
            }

            return $people;
        });
    }

    /**
     * @param  Builder<InboxMessage>  $messages
     * @return Builder<InboxMessage>
     */
    private static function recent(Builder $messages, CarbonInterface $since): Builder
    {
        return $messages->open()->where(fn (Builder $written) => $written
            ->whereNull('inbox_messages.platform_created_at')
            ->orWhere('inbox_messages.platform_created_at', '>=', $since));
    }
}
