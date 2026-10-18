<?php

declare(strict_types=1);

namespace App\Services\Inbox\Drivers;

use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\MessageLimit;
use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\ResumableCursor;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Exceptions\Social\BlueskyPublishException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\InboxHttpClient;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Social\BlueskyLexicon;
use App\Services\Social\BlueskyPdsResolver;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\ConnectionVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

/**
 * Replies and mentions on the channel's Bluesky posts, and its 1:1 chats.
 *
 * @see https://github.com/bluesky-social/atproto/tree/main/packages/api/lexicons/chat/bsky/convo
 */
class BlueskyInboxDriver implements DirectMessageDriver, InboxDriver
{
    use InboxHttpClient;

    private const int PAGE_SIZE = 100;

    private const int MAX_PAGES = 20;

    private const int CHAT_PAGES = 10;

    private const int DIRECT_MESSAGE_LENGTH = 1000;

    /** @return list<ConversationKind> */
    public function kinds(): array
    {
        return [ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage];
    }

    public function poll(SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): PollResult
    {
        if ($kind === ConversationKind::Mention) {
            return $this->pollMentions($account, $cursor, $since);
        }

        if ($kind === ConversationKind::DirectMessage) {
            $this->refreshSession($account);

            return $this->pollDirectMessages($account, $cursor, $since);
        }

        if ($kind !== ConversationKind::Comment) {
            return new PollResult([], $cursor);
        }

        $this->refreshSession($account);

        $stopAt = filled($cursor) ? CarbonImmutable::parse($cursor) : $since;
        $service = data_get($account->meta, 'service') ?? config('trypost.platforms.bluesky.default_service');
        [$notifications, $newestNotification] = $this->notifications($account, $service, $stopAt, ['reply', 'mention']);
        $ownReplies = $this->ownPosts($account, $service, $stopAt)
            ->filter(fn (array $record): bool => $this->isOnOwnThread($account, (string) data_get($record, 'value.reply.root.uri'))
                && ! $this->isOnOwnThread($account, (string) data_get($record, 'value.reply.parent.uri')))
            ->values();
        $newestOwn = self::newestCreatedAt($ownReplies);

        $replies = $notifications
            ->filter(fn (array $item): bool => in_array(data_get($item, 'reason'), ['reply', 'mention'], true)
                && $this->isOnOwnThread($account, (string) data_get($item, 'record.reply.root.uri')))
            ->unique(fn (array $item): string => (string) data_get($item, 'uri'))
            ->values();
        $roots = $replies->map(fn (array $item): string => (string) data_get($item, 'record.reply.root.uri'))
            ->concat($ownReplies->map(fn (array $record): string => (string) data_get($record, 'value.reply.root.uri')))
            ->unique()
            ->values();
        $subjects = $this->subjects($roots);

        $messages = $replies->map(fn (array $item): IncomingMessage => $this->toMessage($account, $item, $subjects))
            ->concat($ownReplies->map(fn (array $record): IncomingMessage => $this->ownReplyToMessage($account, $record, $subjects)))
            ->values()
            ->all();

        $newest = collect([$cursor, $newestNotification, $newestOwn])
            ->filter()
            ->sortBy(fn (string $at): float => (float) CarbonImmutable::parse($at)->format('U.u'))
            ->last();

        return new PollResult(
            messages: $messages,
            cursor: filled($newest) ? (string) $newest : $cursor,
        );
    }

    /**
     * @param  list<string>  $reasons
     * @return array{0: Collection<int, array<string, mixed>>, 1: ?string}
     */
    private function notifications(SocialAccount $account, string $service, CarbonImmutable $stopAt, array $reasons): array
    {
        $items = collect();
        $newest = null;
        $page = null;

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $response = $this->socialHttp()->withToken($account->access_token)
                ->get("{$service}/xrpc/".BlueskyLexicon::LIST_NOTIFICATIONS.'?'.self::xrpcQuery([
                    'reasons' => $reasons,
                    'limit' => self::PAGE_SIZE,
                    'cursor' => $page,
                ]));

            if ($response->failed()) {
                throw BlueskyPublishException::fromApiResponse($response);
            }

            $notifications = collect((array) data_get($response->json(), 'notifications', []));
            $newest ??= data_get($notifications->first(), 'indexedAt');
            $fresh = $notifications->takeWhile(fn (array $item): bool => CarbonImmutable::parse((string) data_get($item, 'indexedAt'))->gt($stopAt));
            $items = $items->concat($fresh);
            $page = data_get($response->json(), 'cursor');

            if ($fresh->count() < $notifications->count() || blank($page)) {
                break;
            }
        }

        return [$items, $newest];
    }

    /**
     * The channel's own posts written after the stop time, newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function ownPosts(SocialAccount $account, string $service, CarbonImmutable $stopAt): Collection
    {
        $records = collect();
        $page = null;

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $response = $this->socialHttp()->withToken($account->access_token)
                ->get("{$service}/xrpc/".BlueskyLexicon::LIST_RECORDS.'?'.self::xrpcQuery([
                    'repo' => $account->platform_user_id,
                    'collection' => BlueskyLexicon::FEED_POST,
                    'limit' => self::PAGE_SIZE,
                    'cursor' => $page,
                ]));

            if ($response->failed()) {
                throw BlueskyPublishException::fromApiResponse($response);
            }

            $batch = collect((array) data_get($response->json(), 'records', []));
            $fresh = $batch->takeWhile(fn (array $record): bool => filled(data_get($record, 'value.createdAt'))
                && CarbonImmutable::parse((string) data_get($record, 'value.createdAt'))->gt($stopAt));
            $records = $records->concat($fresh);
            $page = data_get($response->json(), 'cursor');

            if ($fresh->count() < $batch->count() || blank($page)) {
                break;
            }
        }

        return $records;
    }

    /** @param Collection<int, array<string, mixed>> $records */
    private static function newestCreatedAt(Collection $records): ?string
    {
        return $records
            ->map(fn (array $record): CarbonImmutable => CarbonImmutable::parse((string) data_get($record, 'value.createdAt')))
            ->filter(fn (CarbonImmutable $at): bool => $at->lte(CarbonImmutable::now()))
            ->max()
            ?->toIso8601String();
    }

    /**
     * Mentions of the channel in someone else's thread, the replies to the
     * channel inside those threads, and the channel's own answers there. A
     * mention under the channel's own post is a comment and is left to the
     * comment poll.
     *
     * @see https://github.com/bluesky-social/atproto/blob/main/packages/bsky/lexicons/app/bsky/notification/listNotifications.json
     */
    private function pollMentions(SocialAccount $account, ?string $cursor, CarbonImmutable $since): PollResult
    {
        $this->refreshSession($account);

        $stopAt = filled($cursor) ? CarbonImmutable::parse($cursor) : $since;
        $service = data_get($account->meta, 'service') ?? config('trypost.platforms.bluesky.default_service');
        [$notifications, $newestNotification] = $this->notifications($account, $service, $stopAt, ['mention', 'reply']);
        $records = $this->ownPosts($account, $service, $stopAt);

        $mentions = $notifications
            ->filter(fn (array $item): bool => in_array(data_get($item, 'reason'), ['mention', 'reply'], true)
                && data_get($item, 'author.did') !== $account->platform_user_id
                && ! $this->isOnOwnThread($account, $this->rootUriOf((array) data_get($item, 'record'), (string) data_get($item, 'uri'))))
            ->values();

        $knownParents = $this->storedMessageIds($account, $records->map(fn (array $record): string => (string) data_get($record, 'value.reply.parent.uri'))->filter()->values());
        $ownReplies = $records
            ->filter(fn (array $record): bool => filled(data_get($record, 'value.reply.root.uri'))
                && ! $this->isOnOwnThread($account, (string) data_get($record, 'value.reply.root.uri'))
                && in_array((string) data_get($record, 'value.reply.parent.uri'), $knownParents, true))
            ->values();

        $roots = $mentions->map(fn (array $item): string => $this->rootUriOf((array) data_get($item, 'record'), (string) data_get($item, 'uri')))->unique()->values();
        $subjects = $this->subjects($roots);

        $messages = $mentions->map(fn (array $item): IncomingMessage => $this->mentionToMessage($item, $subjects))
            ->concat($ownReplies->map(fn (array $record): IncomingMessage => $this->ownMentionReplyToMessage($account, $record)))
            ->values()
            ->all();

        $newest = collect([$cursor, $newestNotification, self::newestCreatedAt($ownReplies)])
            ->filter()
            ->sortBy(fn (string $at): float => (float) CarbonImmutable::parse($at)->format('U.u'))
            ->last();

        return new PollResult($messages, filled($newest) ? (string) $newest : $cursor);
    }

    private function isOnOwnThread(SocialAccount $account, string $uri): bool
    {
        return str_starts_with($uri, "at://{$account->platform_user_id}/");
    }

    /** @param array<string, mixed> $record */
    private function rootUriOf(array $record, string $uri): string
    {
        return (string) (data_get($record, 'reply.root.uri') ?: $uri);
    }

    /**
     * @param  Collection<int, string>  $uris
     * @return list<string>
     */
    private function storedMessageIds(SocialAccount $account, Collection $uris): array
    {
        if ($uris->isEmpty()) {
            return [];
        }

        return InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->whereIn('platform_message_hash', $uris->map(fn (string $uri): string => InboxConversation::hashOf($uri))->all())
            ->pluck('platform_message_id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $subjects
     */
    private function mentionToMessage(array $item, array $subjects): IncomingMessage
    {
        $uri = (string) data_get($item, 'uri');
        $rootUri = $this->rootUriOf((array) data_get($item, 'record'), $uri);
        $root = $subjects[$rootUri] ?? [];
        $webApp = config('trypost.platforms.bluesky.web_app');
        $authorHandle = (string) data_get($item, 'author.handle');
        $rootHandle = (string) (data_get($root, 'author.handle') ?: $authorHandle);

        return new IncomingMessage(
            kind: ConversationKind::Mention,
            subject: new IncomingSubject(
                platformId: $rootUri,
                text: data_get($root, 'record.text') ?? ($rootUri === $uri ? data_get($item, 'record.text') : null),
                media: collect((array) data_get($root, 'embed.images', []))->map(fn (array $image): array => ['type' => 'image', 'url' => (string) data_get($image, 'thumb')])->all(),
                url: "{$webApp}/profile/{$rootHandle}/post/".basename($rootUri),
                publishedAt: CarbonImmutable::parse((string) (data_get($root, 'indexedAt') ?: data_get($item, 'indexedAt'))),
                remoteId: null,
                meta: ['cid' => (string) (data_get($item, 'record.reply.root.cid') ?: data_get($item, 'cid'))],
                participantName: data_get($root, 'author.displayName') ?: (data_get($item, 'author.displayName') ?: $authorHandle),
                participantUsername: $rootHandle,
                participantAvatarUrl: data_get($root, 'author.avatar') ?? data_get($item, 'author.avatar'),
            ),
            platformMessageId: $uri,
            platformParentId: data_get($item, 'reason') === 'reply' ? (string) data_get($item, 'record.reply.parent.uri') : null,
            direction: MessageDirection::Inbound,
            authorPlatformId: data_get($item, 'author.did'),
            authorName: data_get($item, 'author.displayName') ?: $authorHandle,
            authorUsername: $authorHandle,
            authorAvatarUrl: data_get($item, 'author.avatar'),
            body: data_get($item, 'record.text'),
            media: [],
            platformUrl: "{$webApp}/profile/{$authorHandle}/post/".basename($uri),
            platformCreatedAt: CarbonImmutable::parse((string) data_get($item, 'indexedAt')),
            meta: ['cid' => (string) data_get($item, 'cid')],
        );
    }

    /**
     * The channel's answer under a stored mention. Its parent is stored, so it
     * joins the parent's conversation and the subject carries only the root.
     *
     * @param  array<string, mixed>  $record
     */
    private function ownMentionReplyToMessage(SocialAccount $account, array $record): IncomingMessage
    {
        $webApp = config('trypost.platforms.bluesky.web_app');

        return new IncomingMessage(
            kind: ConversationKind::Mention,
            subject: new IncomingSubject(
                platformId: (string) data_get($record, 'value.reply.root.uri'),
                text: null,
                media: [],
                url: null,
                publishedAt: null,
                remoteId: null,
                meta: ['cid' => (string) data_get($record, 'value.reply.root.cid')],
            ),
            platformMessageId: (string) data_get($record, 'uri'),
            platformParentId: (string) data_get($record, 'value.reply.parent.uri'),
            direction: MessageDirection::Outbound,
            authorPlatformId: $account->platform_user_id,
            authorName: $account->display_name ?: $account->username,
            authorUsername: $account->username,
            authorAvatarUrl: null,
            body: data_get($record, 'value.text'),
            media: [],
            platformUrl: "{$webApp}/profile/{$account->username}/post/".basename((string) data_get($record, 'uri')),
            platformCreatedAt: CarbonImmutable::parse((string) data_get($record, 'value.createdAt')),
            meta: ['cid' => (string) data_get($record, 'cid')],
        );
    }

    /**
     * atproto reads an array parameter as a repeated key (reasons=reply&reasons=mention),
     * not PHP's bracket form (reasons[0]=reply), which it silently ignores.
     *
     * @param  array<string, mixed>  $parameters
     */
    private static function xrpcQuery(array $parameters): string
    {
        return collect($parameters)
            ->reject(fn (mixed $value): bool => blank($value))
            ->flatMap(fn (mixed $value, string $key): array => collect((array) $value)
                ->map(fn (mixed $item): string => rawurlencode($key).'='.rawurlencode((string) $item))
                ->all())
            ->implode('&');
    }

    public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
    {
        $this->refreshSession($account);

        $conversation = $parent->conversation;
        $sent = app(BlueskyPublisher::class)->replyTo(
            $account,
            $text,
            ['uri' => (string) $parent->platform_message_id, 'cid' => (string) data_get($parent->meta, 'cid')],
            ['uri' => $conversation->platform_conversation_id, 'cid' => (string) data_get($conversation->meta, 'cid')],
        );

        return new SentReply(
            platformMessageId: $sent['uri'],
            platformUrl: $sent['url'],
            platformCreatedAt: CarbonImmutable::now(),
            meta: ['cid' => $sent['cid']],
        );
    }

    public function replyLength(SocialAccount $account): int
    {
        return $account->maxContentLength();
    }

    public function replyLengthUnit(): ReplyLengthUnit
    {
        return ReplyLengthUnit::Characters;
    }

    public function prefillsMention(): bool
    {
        return true;
    }

    public function sendDirectMessage(SocialAccount $account, InboxConversation $conversation, string $text): SentReply
    {
        $this->refreshSession($account);

        $service = $this->chatService($account);
        $response = $this->chat($account)->post("{$service}/xrpc/".BlueskyLexicon::CHAT_SEND_MESSAGE, [
            'convoId' => $conversation->platform_conversation_id,
            'message' => ['text' => $text],
        ]);

        if ($response->failed()) {
            throw BlueskyPublishException::fromApiResponse($response);
        }

        return new SentReply(
            platformMessageId: "chat:{$conversation->platform_conversation_id}:".data_get($response->json(), 'id'),
            platformUrl: null,
            platformCreatedAt: CarbonImmutable::parse((string) data_get($response->json(), 'sentAt', now()->toIso8601String())),
        );
    }

    public function directMessageText(InboxConversation $conversation, string $text): string
    {
        return $text;
    }

    /**
     * Bluesky allows 1000 graphemes; counting code points is never more lenient.
     */
    public function directMessageLimit(SocialAccount $account): MessageLimit
    {
        return new MessageLimit(self::DIRECT_MESSAGE_LENGTH, ReplyLengthUnit::Characters);
    }

    public function hasReplyWindow(): bool
    {
        return false;
    }

    public function replyWindowEndsAt(?CarbonImmutable $lastInboundAt): ?CarbonImmutable
    {
        return null;
    }

    private function chat(SocialAccount $account): PendingRequest
    {
        return $this->socialHttp()
            ->withToken($account->access_token)
            ->withHeaders(['atproto-proxy' => (string) config('trypost.platforms.bluesky.chat_proxy')]);
    }

    /**
     * The entryway answers chat.bsky.* with 501 MethodNotImplemented; only the
     * account's own PDS proxies them to the chat service.
     */
    private function chatService(SocialAccount $account): string
    {
        $service = data_get($account->meta, 'service') ?? config('trypost.platforms.bluesky.default_service');

        return app(BlueskyPdsResolver::class)->cached($account, (string) $service, $this->socialHttp());
    }

    /**
     * Direct convos whose last message is newer than the watermark, each read
     * back to it. listConvos documents no order, so its pages are read in
     * full; group convos are never read.
     */
    private function pollDirectMessages(SocialAccount $account, ?string $cursor, CarbonImmutable $since): PollResult
    {
        $service = $this->chatService($account);
        $place = ResumableCursor::from($cursor);
        $stopAt = $place->stopAt($since);
        $messages = [];
        $newest = $place->newest;
        $page = $place->after;

        for ($i = 0; $i < self::CHAT_PAGES; $i++) {
            $response = $this->chat($account)->get("{$service}/xrpc/".BlueskyLexicon::CHAT_LIST_CONVOS.'?'.self::xrpcQuery(['kind' => 'direct', 'limit' => self::PAGE_SIZE, 'cursor' => $page]));

            if ($response->failed()) {
                throw BlueskyPublishException::fromApiResponse($response);
            }

            foreach ((array) data_get($response->json(), 'convos', []) as $convo) {
                $lastAt = filled(data_get($convo, 'lastMessage.sentAt')) ? CarbonImmutable::parse((string) data_get($convo, 'lastMessage.sentAt')) : null;

                if (count((array) data_get($convo, 'members', [])) !== 2 || ! $lastAt?->gt($stopAt)) {
                    continue;
                }

                $newest = $newest === null || $lastAt->gt($newest) ? $lastAt : $newest;
                $messages = [...$messages, ...$this->convoMessages($account, $service, (array) $convo, $stopAt)];
            }

            $page = data_get($response->json(), 'cursor');

            if (blank($page)) {
                break;
            }
        }

        return new PollResult($messages, $place->next($page, $newest));
    }

    /**
     * @param  array<string, mixed>  $convo
     * @return list<IncomingMessage>
     */
    private function convoMessages(SocialAccount $account, string $service, array $convo, CarbonImmutable $stopAt): array
    {
        $convoId = (string) data_get($convo, 'id');
        $participant = (array) (collect((array) data_get($convo, 'members', []))->first(fn (array $member): bool => data_get($member, 'did') !== $account->platform_user_id) ?? []);
        $messages = [];
        $page = null;

        for ($i = 0; $i < self::CHAT_PAGES; $i++) {
            $response = $this->chat($account)->get("{$service}/xrpc/".BlueskyLexicon::CHAT_GET_MESSAGES.'?'.self::xrpcQuery(['convoId' => $convoId, 'limit' => self::PAGE_SIZE, 'cursor' => $page]));

            if ($response->failed()) {
                throw BlueskyPublishException::fromApiResponse($response);
            }

            $batch = collect((array) data_get($response->json(), 'messages', []))->filter(fn (array $message): bool => filled(data_get($message, 'sentAt')));
            $fresh = $batch->filter(fn (array $message): bool => CarbonImmutable::parse((string) data_get($message, 'sentAt'))->gt($stopAt));
            $messages = [...$messages, ...$fresh->map(fn (array $message): IncomingMessage => $this->chatMessage($account, $convoId, $participant, $message))->values()->all()];
            $page = data_get($response->json(), 'cursor');

            if ($fresh->count() < $batch->count() || blank($page)) {
                break;
            }
        }

        return $messages;
    }

    /**
     * A deletedMessageView carries no text and is stored removed.
     *
     * @param  array<string, mixed>  $participant
     * @param  array<string, mixed>  $message
     */
    private function chatMessage(SocialAccount $account, string $convoId, array $participant, array $message): IncomingMessage
    {
        $isOutbound = data_get($message, 'sender.did') === $account->platform_user_id;
        $handle = data_get($participant, 'handle');
        $name = data_get($participant, 'displayName') ?: $handle;

        return new IncomingMessage(
            kind: ConversationKind::DirectMessage,
            subject: new IncomingSubject(
                platformId: $convoId,
                text: null,
                media: [],
                url: config('trypost.platforms.bluesky.web_app')."/messages/{$convoId}",
                publishedAt: null,
                remoteId: null,
                participantName: $name,
                participantUsername: $handle,
                participantAvatarUrl: data_get($participant, 'avatar'),
            ),
            platformMessageId: "chat:{$convoId}:".data_get($message, 'id'),
            platformParentId: null,
            direction: $isOutbound ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: (string) data_get($message, 'sender.did'),
            authorName: $isOutbound ? ($account->display_name ?: $account->username) : $name,
            authorUsername: $isOutbound ? $account->username : $handle,
            authorAvatarUrl: $isOutbound ? null : data_get($participant, 'avatar'),
            body: data_get($message, 'text'),
            media: [],
            platformUrl: null,
            platformCreatedAt: CarbonImmutable::parse((string) data_get($message, 'sentAt')),
            removed: ! array_key_exists('text', $message),
        );
    }

    private function refreshSession(SocialAccount $account): void
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }
    }

    /**
     * @param  Collection<int, string>  $uris
     * @return array<string, array<string, mixed>>
     */
    private function subjects(Collection $uris): array
    {
        $appview = rtrim((string) config('trypost.platforms.bluesky.public_appview'), '/');

        return $uris->chunk(25)->flatMap(function (Collection $chunk) use ($appview): array {
            $response = $this->socialHttp()->get("{$appview}/xrpc/".BlueskyLexicon::GET_POSTS.'?'.self::xrpcQuery(['uris' => $chunk->values()->all()]));

            return collect($response->successful() ? (array) data_get($response->json(), 'posts', []) : [])
                ->keyBy(fn (array $post): string => (string) data_get($post, 'uri'))
                ->all();
        })->all();
    }

    /** @param array<string, array<string, mixed>> $subjects */
    private function toMessage(SocialAccount $account, array $item, array $subjects): IncomingMessage
    {
        $rootUri = (string) data_get($item, 'record.reply.root.uri');
        $parentUri = (string) data_get($item, 'record.reply.parent.uri');
        $webApp = config('trypost.platforms.bluesky.web_app');
        $authorHandle = (string) data_get($item, 'author.handle');

        return new IncomingMessage(
            kind: ConversationKind::Comment,
            subject: $this->subject($account, $rootUri, (string) data_get($item, 'record.reply.root.cid'), $subjects),
            platformMessageId: (string) data_get($item, 'uri'),
            platformParentId: $parentUri === $rootUri ? null : $parentUri,
            direction: data_get($item, 'author.did') === $account->platform_user_id ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: data_get($item, 'author.did'),
            authorName: data_get($item, 'author.displayName') ?: $authorHandle,
            authorUsername: $authorHandle,
            authorAvatarUrl: data_get($item, 'author.avatar'),
            body: data_get($item, 'record.text'),
            media: [],
            platformUrl: "{$webApp}/profile/{$authorHandle}/post/".basename((string) data_get($item, 'uri')),
            platformCreatedAt: CarbonImmutable::parse((string) data_get($item, 'indexedAt')),
            meta: ['cid' => (string) data_get($item, 'cid')],
        );
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, array<string, mixed>>  $subjects
     */
    private function ownReplyToMessage(SocialAccount $account, array $record, array $subjects): IncomingMessage
    {
        $rootUri = (string) data_get($record, 'value.reply.root.uri');
        $parentUri = (string) data_get($record, 'value.reply.parent.uri');
        $webApp = config('trypost.platforms.bluesky.web_app');

        return new IncomingMessage(
            kind: ConversationKind::Comment,
            subject: $this->subject($account, $rootUri, (string) data_get($record, 'value.reply.root.cid'), $subjects),
            platformMessageId: (string) data_get($record, 'uri'),
            platformParentId: $parentUri === $rootUri ? null : $parentUri,
            direction: MessageDirection::Outbound,
            authorPlatformId: $account->platform_user_id,
            authorName: $account->display_name ?: $account->username,
            authorUsername: $account->username,
            authorAvatarUrl: null,
            body: data_get($record, 'value.text'),
            media: [],
            platformUrl: "{$webApp}/profile/{$account->username}/post/".basename((string) data_get($record, 'uri')),
            platformCreatedAt: CarbonImmutable::parse((string) data_get($record, 'value.createdAt')),
            meta: ['cid' => (string) data_get($record, 'cid')],
        );
    }

    /** @param array<string, array<string, mixed>> $subjects */
    private function subject(SocialAccount $account, string $rootUri, string $rootCid, array $subjects): IncomingSubject
    {
        $root = $subjects[$rootUri] ?? [];
        $webApp = config('trypost.platforms.bluesky.web_app');

        return new IncomingSubject(
            platformId: $rootUri,
            text: data_get($root, 'record.text'),
            media: collect((array) data_get($root, 'embed.images', []))->map(fn (array $image): array => ['type' => 'image', 'url' => (string) data_get($image, 'thumb')])->all(),
            url: "{$webApp}/profile/{$account->username}/post/".basename($rootUri),
            publishedAt: filled(data_get($root, 'indexedAt')) ? CarbonImmutable::parse((string) data_get($root, 'indexedAt')) : null,
            remoteId: basename($rootUri),
            meta: ['cid' => $rootCid],
        );
    }
}
