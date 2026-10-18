<?php

declare(strict_types=1);

namespace App\Services\Inbox\Meta;

use App\Dto\Inbox\InboxWebhookEvents;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\MessageLimit;
use App\Dto\Inbox\MetaMessageParts;
use App\Dto\Inbox\MetaMessagingEndpoint;
use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\ResumableCursor;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Inbox\DirectMessagesUnavailableException;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\MetaGraphRequests;
use App\Services\Inbox\PollBudget;
use App\Services\Social\Meta\GraphError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Direct messages of the three Meta connection methods: Instagram Login,
 * Instagram through a Facebook Page, and Messenger on a Facebook Page.
 * Group threads are listed but cannot be read ("Group messaging is not
 * supported"), so a conversation with more than two participants is skipped.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/messaging-api
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/conversations-api
 * @see https://developers.facebook.com/docs/messenger-platform/conversations
 * @see https://developers.facebook.com/docs/graph-api/reference/page/messages
 * @see https://developers.facebook.com/docs/messenger-platform/instagram/features/webhook
 */
class MetaDirectMessages
{
    use MetaGraphRequests;

    private const int CONVERSATION_PAGES = 10;

    private const int MESSAGE_DETAILS = 20;

    private const int STANDARD_WINDOW_HOURS = 24;

    private const int INSTAGRAM_TEXT_BYTES = 1000;

    private const int MESSENGER_TEXT_CHARACTERS = 2000;

    private Platform $platform = Platform::Instagram;

    /**
     * Reads conversation pages newest first and, inside them, each message on
     * its own. The budget is checked before every conversation and every
     * message once something was read, so a large inbox stops in time and
     * resumes on the same page, skipping the conversations and messages it
     * already read there.
     */
    public function poll(SocialAccount $account, ?string $cursor, CarbonImmutable $since): PollResult
    {
        $this->platform = $account->platform;
        $endpoint = MetaMessagingEndpoint::for($account);
        $place = ResumableCursor::from($cursor);
        $stopAt = $place->stopAt($since);
        $budget = app(PollBudget::class);
        $messages = [];
        $newest = $place->newest;
        $after = $place->after;
        $done = $place->done;
        $hasRead = false;

        for ($page = 0; $page < self::CONVERSATION_PAGES; $page++) {
            $body = $this->graphGet($account, "{$endpoint->graphApi}/{$endpoint->node}/conversations", [
                'platform' => $endpoint->platform,
                'fields' => 'id,updated_time,participants',
                'after' => $after,
            ]);
            $listed = (array) data_get($body, 'data', []);
            $fresh = collect($listed)->filter(fn (array $conversation): bool => self::time(data_get($conversation, 'updated_time'))?->gt($stopAt) ?? false);

            foreach ($fresh as $conversation) {
                $conversationId = (string) data_get($conversation, 'id');

                if (in_array($conversationId, $done, true) || count((array) data_get($conversation, 'participants.data', [])) > 2) {
                    continue;
                }

                if ($hasRead && $budget->exhausted()) {
                    return new PollResult($messages, $place->next($after, $newest, $done), partial: true);
                }

                $read = $this->conversationMessages($account, $endpoint, $conversationId, $stopAt, $done, $hasRead);
                $messages = [...$messages, ...$read['messages']];
                $hasRead = true;

                if (! $read['complete']) {
                    return new PollResult($messages, $place->next($after, $newest, [...$done, ...$read['read']]), partial: true);
                }

                $done = [...array_values(array_diff($done, $read['read'])), $conversationId];
                $updated = self::time(data_get($conversation, 'updated_time'));
                $newest = $newest === null || $updated->gt($newest) ? $updated : $newest;
            }

            $after = $fresh->count() === count($listed) && filled(data_get($body, 'paging.next')) ? data_get($body, 'paging.cursors.after') : null;
            $done = [];

            if (blank($after) || $budget->exhausted()) {
                break;
            }
        }

        return new PollResult($messages, $place->next($after, $newest), partial: filled($after));
    }

    /**
     * One conversation's messages since the stop time, skipping those already
     * read on an earlier run; stops early once the budget is spent.
     *
     * @param  list<string>  $done
     * @return array{messages: list<IncomingMessage>, read: list<string>, complete: bool}
     */
    private function conversationMessages(SocialAccount $account, MetaMessagingEndpoint $endpoint, string $conversationId, CarbonImmutable $stopAt, array $done, bool $hasRead): array
    {
        $listed = collect((array) data_get($this->graphGet($account, "{$endpoint->graphApi}/{$conversationId}", ['fields' => 'messages']), 'messages.data', []))
            ->take(self::MESSAGE_DETAILS);
        $budget = app(PollBudget::class);
        $messages = [];
        $read = array_values(array_intersect($done, $listed->pluck('id')->map(strval(...))->all()));

        foreach ($listed as $message) {
            $messageId = (string) data_get($message, 'id');
            $createdAt = self::time(data_get($message, 'created_time'));

            if (in_array($messageId, $done, true) || ($createdAt && ! $createdAt->gt($stopAt))) {
                continue;
            }

            if ($hasRead && $budget->exhausted()) {
                return ['messages' => $messages, 'read' => $read, 'complete' => false];
            }

            $detail = $this->graphGet($account, "{$endpoint->graphApi}/{$messageId}", ['fields' => MetaMessageContent::NODE_FIELDS]);
            $read[] = $messageId;
            $hasRead = true;
            $parts = MetaMessageContent::fromNode($detail);

            if ($parts->isEmpty(data_get($detail, 'message'))) {
                continue;
            }

            $isOutbound = (string) data_get($detail, 'from.id') === $endpoint->ownId;
            $participant = (array) ($isOutbound ? data_get($detail, 'to.data.0', []) : data_get($detail, 'from', []));

            $messages[] = $this->incoming(
                $account,
                (string) data_get($participant, 'id'),
                (string) data_get($detail, 'id'),
                $isOutbound,
                data_get($detail, 'message'),
                $parts,
                self::time(data_get($detail, 'created_time')) ?? CarbonImmutable::now(),
                false,
                $participant,
                $conversationId,
            );
        }

        return ['messages' => $messages, 'read' => $read, 'complete' => true];
    }

    /**
     * One `entry[].messaging[]` item of a webhook delivery: a message, or a
     * reaction to a stored one. Read receipts, other events without a message
     * and messages that carry nothing to show are ignored.
     *
     * @param  array<string, mixed>  $event
     */
    public function webhookEvents(SocialAccount $account, array $event): InboxWebhookEvents
    {
        $reaction = MetaMessageContent::reactionFromWebhook($event, (string) data_get($event, 'sender.id'));

        return new InboxWebhookEvents(
            messages: array_filter([$this->parseEvent($account, $event)]),
            reactions: array_filter([$reaction]),
        );
    }

    /** @param  array<string, mixed>  $event */
    public function parseEvent(SocialAccount $account, array $event): ?IncomingMessage
    {
        $this->platform = $account->platform;
        $message = data_get($event, 'message');

        if (! is_array($message) || blank(data_get($message, 'mid'))) {
            return null;
        }

        $isEcho = (bool) data_get($message, 'is_echo', false);
        $removed = (bool) data_get($message, 'is_deleted', false);
        $parts = MetaMessageContent::fromWebhook($message);

        if (! $removed && $parts->isEmpty(data_get($message, 'text'))) {
            return null;
        }

        return $this->incoming(
            $account,
            (string) data_get($event, $isEcho ? 'recipient.id' : 'sender.id'),
            (string) data_get($message, 'mid'),
            $isEcho,
            data_get($message, 'text'),
            $parts,
            CarbonImmutable::createFromTimestampMs((int) data_get($event, 'timestamp', now()->getTimestampMs())),
            $removed,
            [],
            null,
        );
    }

    /**
     * @param  array<string, mixed>  $participant  what the message itself tells about the person
     */
    private function incoming(SocialAccount $account, string $participantId, string $messageId, bool $isOutbound, ?string $text, MetaMessageParts $parts, CarbonImmutable $at, bool $removed, array $participant, ?string $conversationId): IncomingMessage
    {
        $profile = $this->profile($account, $participantId);
        $username = data_get($profile, 'username') ?? data_get($participant, 'username');
        $name = data_get($profile, 'name') ?? data_get($participant, 'name') ?? $username;
        $avatar = data_get($profile, 'profile_pic');

        return new IncomingMessage(
            kind: ConversationKind::DirectMessage,
            subject: new IncomingSubject(
                platformId: $participantId,
                text: null,
                media: [],
                url: null,
                publishedAt: null,
                remoteId: null,
                meta: array_filter(['graph_conversation_id' => $conversationId]),
                participantName: $name,
                participantUsername: $username,
                participantAvatarUrl: $avatar,
            ),
            platformMessageId: $messageId,
            platformParentId: null,
            direction: $isOutbound ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: $isOutbound ? (string) $account->platform_user_id : $participantId,
            authorName: $isOutbound ? ($account->display_name ?: $account->username) : $name,
            authorUsername: $isOutbound ? $account->username : $username,
            authorAvatarUrl: $isOutbound ? null : $avatar,
            body: $text,
            media: $parts->media,
            platformUrl: null,
            platformCreatedAt: $at,
            meta: $parts->meta(),
            removed: $removed,
        );
    }

    public function send(SocialAccount $account, InboxConversation $conversation, string $text): SentReply
    {
        $this->platform = $account->platform;
        $endpoint = MetaMessagingEndpoint::for($account);

        $body = $this->graphPost($account, "{$endpoint->graphApi}/{$endpoint->node}/messages", [
            'recipient' => json_encode(['id' => $conversation->platform_conversation_id]),
            'message' => json_encode(['text' => $text]),
            ...$this->messagingType($endpoint),
        ]);

        return new SentReply(
            platformMessageId: (string) data_get($body, 'message_id'),
            platformUrl: null,
            platformCreatedAt: CarbonImmutable::now(),
        );
    }

    /**
     * A Page answers inside the 24 hours with RESPONSE. Instagram Login
     * documents no messaging type, so it sends none.
     *
     * @return array<string, string>
     */
    private function messagingType(MetaMessagingEndpoint $endpoint): array
    {
        return $endpoint->isInstagramLogin ? [] : ['messaging_type' => 'RESPONSE'];
    }

    public function limit(SocialAccount $account): MessageLimit
    {
        return MetaMessagingEndpoint::for($account)->platform === 'instagram'
            ? new MessageLimit(self::INSTAGRAM_TEXT_BYTES, ReplyLengthUnit::Bytes)
            : new MessageLimit(self::MESSENGER_TEXT_CHARACTERS, ReplyLengthUnit::Characters);
    }

    public function windowEndsAt(?CarbonImmutable $lastInboundAt): ?CarbonImmutable
    {
        if (blank($lastInboundAt)) {
            return null;
        }

        return $lastInboundAt->addHours(self::STANDARD_WINDOW_HOURS);
    }

    /**
     * The person's name and picture; empty when Meta does not share them
     * (Messenger needs Business Asset User Profile Access). A refused token
     * still stops the sync.
     *
     * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-instagram-login/messaging-api/user-profile
     * @see https://developers.facebook.com/docs/messenger-platform/identity/user-profile
     *
     * @return array<string, mixed>
     */
    private function profile(SocialAccount $account, string $participantId): array
    {
        if (blank($participantId)) {
            return [];
        }

        $key = "inbox:meta-profile:{$account->id}:{$participantId}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $endpoint = MetaMessagingEndpoint::for($account);

        try {
            $profile = $this->graphGet($account, "{$endpoint->graphApi}/{$participantId}", [
                'fields' => $endpoint->platform === 'instagram' ? 'name,username,profile_pic' : 'name,profile_pic',
            ]);
        } catch (TokenExpiredException $exception) {
            throw $exception;
        } catch (Throwable) {
            $profile = [];
        }

        $profile = filled(data_get($profile, 'name')) || filled(data_get($profile, 'username'))
            ? $profile
            : $this->participant($account, $endpoint, $participantId);

        if (filled($profile)) {
            Cache::put($key, $profile, now()->addDay());
        }

        return $profile;
    }

    /**
     * The person as their conversation with the channel names them. The
     * profile read needs Business Asset User Profile Access (granted by app
     * review), while the conversation's participants carry the name, and on
     * Instagram the username, without it. It never carries the picture.
     *
     * @see https://developers.facebook.com/docs/messenger-platform/identity/user-profile
     * @see https://developers.facebook.com/docs/graph-api/reference/page/conversations
     *
     * @return array<string, mixed>
     */
    private function participant(SocialAccount $account, MetaMessagingEndpoint $endpoint, string $participantId): array
    {
        try {
            $conversations = $this->graphGet($account, "{$endpoint->graphApi}/{$endpoint->node}/conversations", [
                'platform' => $endpoint->platform,
                'user_id' => $participantId,
                'fields' => 'participants',
            ]);
        } catch (TokenExpiredException $exception) {
            throw $exception;
        } catch (Throwable) {
            return [];
        }

        $participant = collect((array) data_get($conversations, 'data.0.participants.data', []))
            ->first(fn (mixed $person): bool => (string) data_get($person, 'id') === $participantId);

        return array_filter([
            'name' => data_get($participant, 'name'),
            'username' => data_get($participant, 'username'),
        ]);
    }

    /**
     * The owner turning off connected tools' access to messages is a setting
     * of the account, not a failure (code 200, subcode 2534041).
     *
     * @see https://developers.facebook.com/docs/messenger-platform/error-codes
     */
    protected function graphException(Response $response): SocialPublishException
    {
        if (GraphError::isMessagesAccessDisabled($response)) {
            throw new DirectMessagesUnavailableException((string) data_get($response->json(), 'error.message'));
        }

        return $this->platform === Platform::Facebook
            ? FacebookPublishException::fromApiResponse($response)
            : InstagramPublishException::fromApiResponse($response);
    }

    private static function time(mixed $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value) : null;
    }
}
