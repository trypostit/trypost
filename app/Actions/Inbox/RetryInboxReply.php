<?php

declare(strict_types=1);

namespace App\Actions\Inbox;

use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Inbox\MessageDirection;
use App\Jobs\Inbox\SendInboxReply;
use App\Models\InboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryInboxReply
{
    public static function execute(InboxMessage $failed): InboxMessage
    {
        $retried = DB::transaction(function () use ($failed): InboxMessage {
            $message = InboxMessage::query()->lockForUpdate()->findOrFail($failed->id);

            if ($message->direction !== MessageDirection::Outbound || $message->delivery_status !== DeliveryStatus::Failed) {
                throw ValidationException::withMessages(['message' => __('inbox.errors.not_retryable')]);
            }

            if ($message->conversation?->isFlat()) {
                SendInboxDirectMessage::assertWindowOpen($message->conversation);
            }

            $message->forceFill([
                'delivery_status' => DeliveryStatus::Sending,
                'delivery_error' => null,
            ])->save();

            return $message;
        });

        SendInboxReply::dispatch($retried->id)->afterCommit();

        return $retried;
    }
}
