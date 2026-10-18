<?php

declare(strict_types=1);

namespace App\Jobs\Inbox;

use App\Actions\Inbox\MarkInboxTreeAnswered;
use App\Actions\Inbox\RecountInboxConversation;
use App\Actions\Inbox\SendInboxDirectMessage;
use App\Actions\Media\DeleteOwnedMedia;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\DeliveryStatus;
use App\Enums\Post\Origin;
use App\Events\InboxUpdated;
use App\Exceptions\Inbox\DirectMessagesUnavailableException;
use App\Exceptions\Inbox\ReplyWindowClosedException;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\InboxDrivers;
use App\Support\Inbox\RedactedError;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendInboxReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public string $messageId)
    {
        $this->onQueue('inbox-replies');
    }

    public function handle(): void
    {
        $message = InboxMessage::query()->with(['socialAccount', 'parent', 'conversation'])->find($this->messageId);

        if (! $message || $message->delivery_status !== DeliveryStatus::Sending) {
            return;
        }

        $account = $message->socialAccount;
        $driver = $account ? app(InboxDrivers::class)->for($account->platform) : null;

        if ($driver && method_exists($driver, 'forSending')) {
            $driver = $driver->forSending();
        }

        try {
            if (! $driver) {
                throw new PlatformUnavailableException('No inbox driver available for this reply.');
            }

            $sent = $message->conversation?->isFlat()
                ? $this->sendDirectMessage($driver, $message)
                : $this->sendReply($driver, $message);
        } catch (ReplyWindowClosedException) {
            $this->markFailed($message, null, InboxMessage::WINDOW_CLOSED);

            return;
        } catch (DirectMessagesUnavailableException) {
            $this->markFailed($message, null, InboxMessage::MESSAGES_UNAVAILABLE);

            return;
        } catch (Throwable $exception) {
            $this->markFailed($message, $exception);

            return;
        }

        $outbound = $this->store($message, $sent);

        MarkInboxTreeAnswered::execute($outbound);

        $conversation = InboxConversation::query()->find($outbound->inbox_conversation_id);

        if ($conversation) {
            RecountInboxConversation::execute($conversation);
            InboxUpdated::dispatch($conversation->workspace_id, $conversation->id);
        }
    }

    private function sendDirectMessage(InboxDriver $driver, InboxMessage $message): SentReply
    {
        $account = $message->socialAccount;

        if (! $driver instanceof DirectMessageDriver
            || ! $account->platform->inboxKindEnabled(ConversationKind::DirectMessage)
            || ! $account->hasInboxAccessFor(ConversationKind::DirectMessage)) {
            throw new DirectMessagesUnavailableException;
        }

        SendInboxDirectMessage::assertWindowOpen($message->conversation);

        return $driver->sendDirectMessage($message->socialAccount, $message->conversation, $driver->directMessageText($message->conversation, $message->body));
    }

    private function sendReply(InboxDriver $driver, InboxMessage $message): SentReply
    {
        if (! $message->parent) {
            throw new PlatformUnavailableException('No inbox driver available for this reply.');
        }

        return $driver->reply($message->socialAccount, $message->parent, $message->body);
    }

    private function store(InboxMessage $message, SentReply $sent): InboxMessage
    {
        $hash = InboxConversation::hashOf($sent->platformMessageId);

        $echo = $this->findEcho($message, $hash);

        if (! $echo) {
            try {
                DB::transaction(fn () => $message->forceFill([
                    'platform_message_id' => $sent->platformMessageId,
                    'platform_message_hash' => $hash,
                    'platform_url' => $sent->platformUrl,
                    'platform_created_at' => $sent->platformCreatedAt,
                    'meta' => $sent->meta,
                    'review_reply_state' => $sent->reviewReplyState,
                    'delivery_status' => DeliveryStatus::Sent,
                    'delivery_error' => null,
                ])->save());

                return $message;
            } catch (UniqueConstraintViolationException $exception) {
                $echo = $this->findEcho($message, $hash) ?? throw $exception;
            }
        }

        return DB::transaction(function () use ($message, $echo, $sent): InboxMessage {
            $echo->forceFill([
                'sent_by_user_id' => $message->sent_by_user_id,
                'origin' => Origin::TryPost,
                'body' => $message->body,
                'review_reply_state' => $sent->reviewReplyState ?? $echo->review_reply_state,
                'delivery_status' => DeliveryStatus::Sent,
                'delivery_error' => null,
                'removed_at' => null,
                ...$this->replacedReviewReply($sent),
            ])->save();
            DeleteOwnedMedia::forInboxMessages([$message->id]);
            $message->delete();

            return $echo;
        });
    }

    /**
     * A review keeps one reply on Google, so the stored reply this send
     * replaced takes the new reply's time and drops the old moderation notes.
     *
     * @return array<string, mixed>
     */
    private function replacedReviewReply(SentReply $sent): array
    {
        return filled($sent->reviewReplyState)
            ? ['platform_created_at' => $sent->platformCreatedAt, 'meta' => $sent->meta]
            : [];
    }

    private function findEcho(InboxMessage $message, string $hash): ?InboxMessage
    {
        return InboxMessage::query()
            ->where('social_account_id', $message->social_account_id)
            ->where('platform_message_hash', $hash)
            ->where('id', '!=', $message->id)
            ->first();
    }

    public function failed(?Throwable $exception): void
    {
        $message = InboxMessage::query()->find($this->messageId);

        if (! $message || $message->delivery_status !== DeliveryStatus::Sending) {
            return;
        }

        $message->forceFill([
            'delivery_status' => DeliveryStatus::Failed,
            'delivery_error' => InboxMessage::REPLY_FAILED,
        ])->save();

        $conversation = InboxConversation::query()->find($message->inbox_conversation_id);

        if ($conversation) {
            InboxUpdated::dispatch($conversation->workspace_id, $conversation->id);
        }
    }

    private function markFailed(InboxMessage $message, ?Throwable $exception, string $error = InboxMessage::REPLY_FAILED): void
    {
        $account = $message->socialAccount;

        $message->forceFill([
            'delivery_status' => DeliveryStatus::Failed,
            'delivery_error' => $error,
        ])->save();

        if ($exception instanceof TokenExpiredException) {
            $account?->markAsTokenExpired($exception->getMessage());
        } elseif ($exception && ! $exception instanceof PlatformUnavailableException
            && ! ($exception instanceof SocialPublishException && $exception->isLimit())) {
            RedactedError::report($exception);
        }

        $conversation = InboxConversation::query()->find($message->inbox_conversation_id);

        if ($conversation) {
            InboxUpdated::dispatch($conversation->workspace_id, $conversation->id);
        }
    }
}
