<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\Post\Origin;
use App\Exceptions\Inbox\ReplyWindowClosedException;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SendInboxDirectMessage
{
    public static function execute(InboxConversation $conversation, User $user, string $text): InboxMessage
    {
        $text = Str::trim($text);
        $account = $conversation->socialAccount;
        $driver = $conversation->directMessageDriver();

        if (! $driver) {
            throw ValidationException::withMessages(['conversation' => __('inbox.errors.not_direct_message')]);
        }

        self::assertWindowOpen($conversation);

        if ($text === '') {
            throw ValidationException::withMessages(['text' => __('inbox.errors.empty_message')]);
        }

        $limit = $driver->directMessageLimit($account);

        if (! $limit->fits($driver->directMessageText($conversation, $text))) {
            throw ValidationException::withMessages(['text' => $limit->unit === ReplyLengthUnit::Bytes
                ? __('inbox.errors.message_too_long_for_network', ['network' => $account->platform->label()])
                : __('inbox.errors.message_too_long', ['max' => $limit->max])]);
        }

        $message = DB::transaction(fn (): InboxMessage => InboxMessage::query()->create([
            'inbox_conversation_id' => $conversation->id,
            'social_account_id' => $account->id,
            'direction' => MessageDirection::Outbound,
            'sent_by_user_id' => $user->id,
            'origin' => Origin::TryPost,
            'delivery_status' => DeliveryStatus::Sending,
            'author_platform_id' => $account->platform_user_id,
            'author_name' => $account->display_name,
            'author_username' => $account->username,
            'body' => $text,
            'platform_created_at' => now(),
        ]));

        SendInboxReply::dispatch($message->id)->afterCommit();

        return $message;
    }

    /**
     * Refuses a message once the network's time to answer the person's last
     * message has run out (Instagram and Messenger: 24 hours), or before the
     * person ever wrote, read live.
     *
     * @throws ReplyWindowClosedException
     */
    public static function assertWindowOpen(InboxConversation $conversation): void
    {
        if ($conversation->isReplyWindowOpen()) {
            return;
        }

        $endsAt = $conversation->liveReplyWindowEndsAt();
        $hours = $endsAt && $conversation->last_inbound_at ? (int) round($conversation->last_inbound_at->diffInHours($endsAt)) : 24;

        throw ReplyWindowClosedException::withMessages(['text' => __('inbox.errors.window_closed', [
            'network' => $conversation->socialAccount?->platform->label(),
            'person' => filled($conversation->participant_username) ? "@{$conversation->participant_username}" : $conversation->participant_name,
            'window' => trans_choice('inbox.errors.window_hours', $hours),
        ])]);
    }
}
