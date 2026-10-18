<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\Post\Origin;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxMessage;
use App\Models\User;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReplyToInboxMessage
{
    public static function execute(InboxMessage $parent, User $user, string $text): InboxMessage
    {
        if ($parent->conversation?->isFlat()) {
            throw ValidationException::withMessages(['message' => __('inbox.errors.reply_in_conversation')]);
        }

        $text = Str::trim($text);
        $account = $parent->socialAccount;
        $driver = app(InboxDrivers::class)->for($account->platform);
        $max = $driver?->replyLength($account) ?? $account->maxContentLength();
        $length = $driver?->replyLengthUnit()->measure($text) ?? mb_strlen($text);

        if ($parent->direction !== MessageDirection::Inbound || filled($parent->removed_at) || blank($parent->platform_message_id)) {
            throw ValidationException::withMessages(['message' => __('inbox.errors.not_replyable')]);
        }

        if ($text === '') {
            throw ValidationException::withMessages(['text' => __('inbox.errors.empty_reply')]);
        }

        if ($length > $max) {
            throw ValidationException::withMessages(['text' => ($driver?->replyLengthUnit() ?? ReplyLengthUnit::Characters)->tooLongMessage($max)]);
        }

        $reply = DB::transaction(fn (): InboxMessage => InboxMessage::query()->create([
            'inbox_conversation_id' => $parent->inbox_conversation_id,
            'social_account_id' => $account->id,
            'parent_id' => $parent->id,
            'root_id' => $parent->root_id ?? $parent->id,
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

        SendInboxReply::dispatch($reply->id)->afterCommit();

        return $reply;
    }
}
