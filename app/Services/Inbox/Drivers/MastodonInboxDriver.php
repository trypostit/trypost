<?php

declare(strict_types=1);

namespace App\Services\Inbox\Drivers;

use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\MessageLimit;
use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Exceptions\Social\MastodonPublishException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\InboxHttpClient;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Support\Inbox\RedactedError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Replies and mentions on the channel's statuses, and its 1:1 private
 * mentions (direct visibility), read as conversations.
 *
 * @see https://docs.joinmastodon.org/methods/notifications/
 * @see https://docs.joinmastodon.org/methods/statuses/
 * @see https://docs.joinmastodon.org/methods/conversations/
 */
class MastodonInboxDriver implements DirectMessageDriver, InboxDriver
{
    use InboxHttpClient;

    /** Statuses Mastodon answers for a status that no longer exists; any other failure is retried. */
    private const array GONE_STATUSES = ['404', '410'];

    private const int TRANSIENT_RETRY_MINUTES = 5;

    private const int NOTIFICATIONS_PAGE_SIZE = 80;

    private const int STATUSES_PAGE_SIZE = 40;

    private const int MAX_PAGES = 10;

    private const int CONVERSATIONS_PAGE_SIZE = 40;

    /**
     * Mastodon allows 300 requests per 5 minutes per account and, separately,
     * 300 per 5 minutes per IP (shared by every channel of the instance, see
     * AnalyticsRateLimits); a poll reads at most this many threads and leaves
     * the rest in the cursor's backlog.
     *
     * @see https://docs.joinmastodon.org/api/rate-limits/
     */
    public const int MAX_CONTEXT_CALLS = 60;

    private const int MAX_BACKLOG = 400;

    /**
     * A status that keeps failing for a reason other than being gone or a
     * rate limit is read again at most this many polls, then dropped and
     * reported once, so it can never hold the backlog forever.
     */
    private const int MAX_ATTEMPTS = 5;

    private const int RATE_LIMIT_WINDOW_MINUTES = 5;

    private const array REPLY_VISIBILITIES = ['public', 'unlisted', 'private'];

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
            return $this->pollDirectMessages($account, $cursor, $since);
        }

        if ($kind !== ConversationKind::Comment) {
            return new PollResult([], $cursor);
        }

        $instance = $account->mastodonInstance();
        $cursors = (array) json_decode((string) $cursor, true);

        [$mentions, $newestNotification] = $this->feed(
            $account,
            "{$instance}/api/v1/notifications",
            ['types[]' => 'mention', 'limit' => self::NOTIFICATIONS_PAGE_SIZE],
            data_get($cursors, 'notifications'),
            $since,
        );
        [$own, $newestStatus] = $this->feed(
            $account,
            "{$instance}/api/v1/accounts/{$account->platform_user_id}/statuses",
            ['exclude_reblogs' => 'true', 'limit' => self::STATUSES_PAGE_SIZE],
            data_get($cursors, 'statuses'),
            $since,
        );

        $candidates = collect((array) data_get($cursors, 'backlog', []))
            ->concat($mentions->map(fn (array $notification): array => (array) data_get($notification, 'status', []))
                ->concat($own)
                ->filter(fn (array $status): bool => filled(data_get($status, 'in_reply_to_id')) && data_get($status, 'visibility') !== 'direct')
                ->map(fn (array $status): string => (string) data_get($status, 'id')))
            ->unique()
            ->values();

        $messages = [];
        $handled = [];
        $attempts = (array) data_get($cursors, 'attempts', []);
        $contextCalls = 0;
        $retryAt = null;
        $stoppedAt = null;

        foreach ($candidates as $index => $id) {
            if (isset($handled[$id])) {
                continue;
            }

            if ($contextCalls >= self::MAX_CONTEXT_CALLS) {
                $stoppedAt = $index;

                break;
            }

            try {
                $contextCalls++;
                $root = data_get($this->context($account, $instance, $id), 'ancestors.0');

                if (! is_array($root) || (string) data_get($root, 'account.id') !== $account->platform_user_id || isset($handled[(string) data_get($root, 'id')])) {
                    $handled[$id] = true;

                    continue;
                }

                $rootId = (string) data_get($root, 'id');

                if ($contextCalls >= self::MAX_CONTEXT_CALLS) {
                    $stoppedAt = $index;

                    break;
                }

                $contextCalls++;
                $descendants = (array) data_get($this->context($account, $instance, $rootId), 'descendants', []);
            } catch (MastodonPublishException $exception) {
                if (in_array($exception->platformErrorCode, self::GONE_STATUSES, true) || (! $exception->isLimit() && self::failedTooOften($attempts, $id, $exception))) {
                    $handled[$id] = true;

                    continue;
                }

                $retryAt = $exception->isLimit()
                    ? ($exception->retryAt ?? now()->addMinutes(self::RATE_LIMIT_WINDOW_MINUTES))
                    : now()->addMinutes(self::TRANSIENT_RETRY_MINUTES);
                $stoppedAt = $index;

                break;
            } catch (ConnectionException $exception) {
                if (self::failedTooOften($attempts, $id, $exception)) {
                    $handled[$id] = true;

                    continue;
                }

                $retryAt = now()->addMinutes(self::TRANSIENT_RETRY_MINUTES);
                $stoppedAt = $index;

                break;
            }

            unset($attempts[$id]);
            $handled[$id] = true;
            $handled[$rootId] = true;
            $subject = $this->subject($root);

            foreach ($descendants as $descendant) {
                $handled[(string) data_get($descendant, 'id')] = true;

                if (data_get($descendant, 'visibility') !== 'direct') {
                    $messages[(string) data_get($descendant, 'id')] = $this->toMessage($account, $descendant, $subject, $rootId);
                }
            }
        }

        $backlog = $stoppedAt === null ? [] : $candidates->slice($stoppedAt)
            ->reject(fn (string $id): bool => isset($handled[$id]))
            ->take(self::MAX_BACKLOG)
            ->values()
            ->all();

        return new PollResult(
            messages: array_values($messages),
            cursor: self::feedCursor([
                'notifications' => $newestNotification ?? data_get($cursors, 'notifications'),
                'statuses' => $newestStatus ?? data_get($cursors, 'statuses'),
                'backlog' => $backlog,
            ], $attempts),
            retryAt: $retryAt,
        );
    }

    /**
     * Mentions of the channel outside its own threads, each its own
     * conversation, plus the replies under them: a mention answering a stored
     * message and the channel's own answers join that message's conversation.
     * A reply to one of the channel's statuses, or inside a thread the channel
     * started, belongs to the comment poll. Direct statuses are messages, not
     * mentions.
     *
     * @see https://docs.joinmastodon.org/methods/notifications/
     * @see https://docs.joinmastodon.org/entities/Status/#visibility
     */
    private function pollMentions(SocialAccount $account, ?string $cursor, CarbonImmutable $since): PollResult
    {
        $instance = $account->mastodonInstance();
        $cursors = (array) json_decode((string) $cursor, true);

        [$notifications, $newestNotification] = $this->feed(
            $account,
            "{$instance}/api/v1/notifications",
            ['types[]' => 'mention', 'limit' => self::NOTIFICATIONS_PAGE_SIZE],
            data_get($cursors, 'notifications'),
            $since,
        );
        [$own, $newestStatus] = $this->feed(
            $account,
            "{$instance}/api/v1/accounts/{$account->platform_user_id}/statuses",
            ['exclude_reblogs' => 'true', 'limit' => self::STATUSES_PAGE_SIZE],
            data_get($cursors, 'statuses'),
            $since,
        );

        $statuses = $notifications
            ->map(fn (array $notification): array => (array) data_get($notification, 'status', []))
            ->filter(fn (array $status): bool => filled(data_get($status, 'id'))
                && data_get($status, 'visibility') !== 'direct'
                && (string) data_get($status, 'account.id') !== $account->platform_user_id)
            ->unique(fn (array $status): string => (string) data_get($status, 'id'))
            ->values();
        $own = $own->filter(fn (array $status): bool => filled(data_get($status, 'in_reply_to_id')) && data_get($status, 'visibility') !== 'direct')->values();
        $stored = $this->storedMentionParents($account, $statuses->concat($own)->map(fn (array $status): string => (string) data_get($status, 'in_reply_to_id'))->filter()->values());

        $messages = [];
        $calls = 0;
        $retryAt = null;
        $backlog = [];
        $attempts = (array) data_get($cursors, 'attempts', []);
        $queue = collect((array) data_get($cursors, 'backlog', []))
            ->reject(fn (mixed $id): bool => $statuses->contains(fn (array $status): bool => (string) data_get($status, 'id') === (string) $id))
            ->map(fn (mixed $id): string => (string) $id)
            ->concat($statuses)
            ->values();

        foreach ($queue as $index => $item) {
            if ($retryAt !== null || $calls >= self::MAX_CONTEXT_CALLS) {
                $backlog[] = is_array($item) ? (string) data_get($item, 'id') : $item;

                continue;
            }

            try {
                $status = is_array($item) ? $item : $this->status($account, $instance, $item, $calls);

                if ($status === null) {
                    continue;
                }

                $parentId = (string) data_get($status, 'in_reply_to_id');

                if (! is_array($item) && $parentId !== '' && ! isset($stored[$parentId])) {
                    $stored += $this->storedMentionParents($account, collect([$parentId]));
                }

                if ($parentId !== '' && ! isset($stored[$parentId])) {
                    if ((string) data_get($status, 'in_reply_to_account_id') === $account->platform_user_id) {
                        continue;
                    }

                    if ($calls >= self::MAX_CONTEXT_CALLS) {
                        $backlog[] = (string) data_get($status, 'id');

                        continue;
                    }

                    if ($this->isOnOwnThread($account, $instance, (string) data_get($status, 'id'), $calls)) {
                        continue;
                    }

                    $parentId = '';
                }
            } catch (MastodonPublishException $exception) {
                if (! $exception->isLimit() && self::failedTooOften($attempts, is_array($item) ? (string) data_get($item, 'id') : $item, $exception)) {
                    continue;
                }

                $retryAt = $exception->isLimit()
                    ? ($exception->retryAt ?? now()->addMinutes(self::RATE_LIMIT_WINDOW_MINUTES))
                    : now()->addMinutes(self::TRANSIENT_RETRY_MINUTES);
                $backlog[] = is_array($item) ? (string) data_get($item, 'id') : $item;

                continue;
            } catch (ConnectionException $exception) {
                if (self::failedTooOften($attempts, is_array($item) ? (string) data_get($item, 'id') : $item, $exception)) {
                    continue;
                }

                $retryAt = now()->addMinutes(self::TRANSIENT_RETRY_MINUTES);
                $backlog[] = is_array($item) ? (string) data_get($item, 'id') : $item;

                continue;
            }

            if (data_get($status, 'visibility') !== 'direct' && (string) data_get($status, 'account.id') !== $account->platform_user_id) {
                $messages[] = $this->mentionToMessage($status, $parentId === '' ? null : $parentId, MessageDirection::Inbound);
            }
        }

        foreach ($own as $status) {
            $parentId = (string) data_get($status, 'in_reply_to_id');

            if (data_get($stored, $parentId) === ConversationKind::Mention->value) {
                $messages[] = $this->mentionToMessage($status, $parentId, MessageDirection::Outbound);
            }
        }

        $backlog = array_slice(array_values(array_unique($backlog)), 0, self::MAX_BACKLOG);

        return new PollResult(
            messages: $messages,
            cursor: self::feedCursor([
                'notifications' => $newestNotification ?? data_get($cursors, 'notifications'),
                'statuses' => $newestStatus ?? data_get($cursors, 'statuses'),
                'backlog' => $backlog,
            ], $attempts),
            retryAt: $retryAt,
        );
    }

    /**
     * A status kept in the backlog, read again. One that is gone is dropped.
     *
     * @return array<string, mixed>|null
     */
    private function status(SocialAccount $account, string $instance, string $statusId, int &$calls): ?array
    {
        $calls++;

        try {
            return (array) $this->get($account, "{$instance}/api/v1/statuses/{$statusId}", []);
        } catch (MastodonPublishException $exception) {
            if (in_array($exception->platformErrorCode, self::GONE_STATUSES, true)) {
                return null;
            }

            throw $exception;
        }
    }

    /**
     * Whether the status sits in a thread the channel started. A status that
     * is gone counts as handled.
     */
    private function isOnOwnThread(SocialAccount $account, string $instance, string $statusId, int &$calls): bool
    {
        $calls++;

        try {
            $root = data_get($this->context($account, $instance, $statusId), 'ancestors.0');
        } catch (MastodonPublishException $exception) {
            if (in_array($exception->platformErrorCode, self::GONE_STATUSES, true)) {
                return true;
            }

            throw $exception;
        }

        return (string) data_get($root, 'account.id') === $account->platform_user_id;
    }

    /**
     * The stored messages among these ids, with the kind of their conversation.
     *
     * @param  Collection<int, string>  $ids
     * @return array<string, string>
     */
    private function storedMentionParents(SocialAccount $account, Collection $ids): array
    {
        if ($ids->isEmpty()) {
            return [];
        }

        return InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->whereIn('platform_message_hash', $ids->unique()->map(fn (string $id): string => InboxConversation::hashOf($id))->values()->all())
            ->with('conversation:id,kind')
            ->get(['id', 'inbox_conversation_id', 'platform_message_id'])
            ->mapWithKeys(fn (InboxMessage $message): array => [$message->platform_message_id => $message->conversation->kind->value])
            ->all();
    }

    /** @param array<string, mixed> $status */
    private function mentionToMessage(array $status, ?string $parentId, MessageDirection $direction): IncomingMessage
    {
        $text = $this->plainText((string) data_get($status, 'content'));
        $media = $this->media((array) data_get($status, 'media_attachments', []));
        $createdAt = CarbonImmutable::parse((string) data_get($status, 'created_at'));
        $url = data_get($status, 'url') ?? data_get($status, 'uri');
        $authorName = data_get($status, 'account.display_name') ?: data_get($status, 'account.username');

        return new IncomingMessage(
            kind: ConversationKind::Mention,
            subject: new IncomingSubject(
                platformId: (string) data_get($status, 'id'),
                text: $text,
                media: $media,
                url: $url,
                publishedAt: $createdAt,
                remoteId: null,
                participantName: $authorName,
                participantUsername: data_get($status, 'account.acct'),
                participantAvatarUrl: data_get($status, 'account.avatar'),
            ),
            platformMessageId: (string) data_get($status, 'id'),
            platformParentId: $parentId,
            direction: $direction,
            authorPlatformId: (string) data_get($status, 'account.id'),
            authorName: $authorName,
            authorUsername: data_get($status, 'account.acct'),
            authorAvatarUrl: data_get($status, 'account.avatar'),
            body: $text,
            media: $media,
            platformUrl: $url,
            platformCreatedAt: $createdAt,
            meta: array_filter([
                'visibility' => data_get($status, 'visibility'),
                'spoiler_text' => data_get($status, 'spoiler_text'),
            ], fn (mixed $value): bool => filled($value)),
        );
    }

    public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
    {
        $visibility = in_array(data_get($parent->meta, 'visibility'), self::REPLY_VISIBILITIES, true)
            ? (string) data_get($parent->meta, 'visibility')
            : 'public';

        $response = $this->socialHttp()->withToken($account->access_token)
            ->withHeaders(['Idempotency-Key' => hash('sha256', "{$parent->id}:{$text}")])
            ->post("{$account->mastodonInstance()}/api/v1/statuses", array_filter([
                'status' => $text,
                'in_reply_to_id' => $parent->platform_message_id,
                'visibility' => $visibility,
                'spoiler_text' => data_get($parent->meta, 'spoiler_text'),
            ], fn (mixed $value): bool => filled($value)));

        if ($response->failed()) {
            throw MastodonPublishException::fromApiResponse($response);
        }

        return new SentReply(
            platformMessageId: (string) $response->json('id'),
            platformUrl: $response->json('url'),
            platformCreatedAt: CarbonImmutable::parse((string) $response->json('created_at')),
            meta: ['visibility' => $visibility],
        );
    }

    /**
     * 1:1 conversations whose last status is newer than the watermark, read
     * oldest first so a poll stopped by the call budget or the rate limit
     * resumes where it stopped. A conversation with several people is never
     * read.
     */
    private function pollDirectMessages(SocialAccount $account, ?string $cursor, CarbonImmutable $since): PollResult
    {
        $instance = $account->mastodonInstance();
        $place = json_decode((string) $cursor, true);
        $watermark = is_array($place) ? data_get($place, 'watermark') : $cursor;
        $attempts = is_array($place) ? (array) data_get($place, 'attempts', []) : [];
        $stopAt = filled($watermark) ? CarbonImmutable::parse((string) $watermark) : $since;
        $conversations = collect();
        $maxId = null;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $listed = collect((array) $this->get($account, "{$instance}/api/v1/conversations", ['limit' => self::CONVERSATIONS_PAGE_SIZE, 'max_id' => $maxId]));
            $fresh = $listed->filter(fn (array $conversation): bool => filled(data_get($conversation, 'last_status.created_at'))
                && CarbonImmutable::parse((string) data_get($conversation, 'last_status.created_at'))->gt($stopAt));
            $conversations = $conversations->concat($fresh);
            $maxId = (string) data_get($listed->last(), 'id');

            if ($fresh->count() < $listed->count() || $listed->count() < self::CONVERSATIONS_PAGE_SIZE) {
                break;
            }
        }

        $messages = [];
        $newest = null;
        $retryAt = null;

        $pending = $conversations
            ->filter(fn (array $conversation): bool => count((array) data_get($conversation, 'accounts', [])) === 1)
            ->sortBy(fn (array $conversation): float => (float) CarbonImmutable::parse((string) data_get($conversation, 'last_status.created_at'))->format('U.u'))
            ->take(self::MAX_CONTEXT_CALLS);

        foreach ($pending as $conversation) {
            $last = (array) data_get($conversation, 'last_status');

            $conversationId = (string) data_get($conversation, 'id');

            try {
                $context = $this->context($account, $instance, (string) data_get($last, 'id'));
            } catch (MastodonPublishException $exception) {
                if (in_array($exception->platformErrorCode, self::GONE_STATUSES, true)) {
                    $newest = CarbonImmutable::parse((string) data_get($last, 'created_at'));

                    continue;
                }

                if (! $exception->isLimit() && self::failedTooOften($attempts, $conversationId, $exception)) {
                    $newest = CarbonImmutable::parse((string) data_get($last, 'created_at'));

                    continue;
                }

                $retryAt = $exception->isLimit()
                    ? ($exception->retryAt ?? now()->addMinutes(self::RATE_LIMIT_WINDOW_MINUTES))
                    : now()->addMinutes(self::TRANSIENT_RETRY_MINUTES);

                break;
            } catch (ConnectionException $exception) {
                if (self::failedTooOften($attempts, $conversationId, $exception)) {
                    $newest = CarbonImmutable::parse((string) data_get($last, 'created_at'));

                    continue;
                }

                $retryAt = now()->addMinutes(self::TRANSIENT_RETRY_MINUTES);

                break;
            }

            unset($attempts[$conversationId]);

            $participant = (array) data_get($conversation, 'accounts.0');
            $people = collect([(string) $account->platform_user_id, (string) data_get($participant, 'id')])->sort()->values()->all();

            foreach ([...(array) data_get($context, 'ancestors', []), $last, ...(array) data_get($context, 'descendants', [])] as $status) {
                if (data_get($status, 'visibility') === 'direct'
                    && self::peopleOf((array) $status) === $people
                    && CarbonImmutable::parse((string) data_get($status, 'created_at'))->gt($stopAt)) {
                    $messages[(string) data_get($status, 'id')] = $this->directStatus($account, (string) data_get($conversation, 'id'), $participant, (array) $status);
                }
            }

            $newest = CarbonImmutable::parse((string) data_get($last, 'created_at'));
        }

        $watermark = $newest?->toIso8601String() ?? $watermark;

        return new PollResult(
            array_values($messages),
            $attempts === [] ? $watermark : (string) json_encode(['watermark' => $watermark, 'attempts' => $attempts]),
            $retryAt,
        );
    }

    /**
     * Counts one more failed read of an id; past MAX_ATTEMPTS the id is
     * forgotten, reported once (redacted) and the caller drops it.
     *
     * @param  array<string, int>  $attempts
     */
    private static function failedTooOften(array &$attempts, string $id, Throwable $exception): bool
    {
        $attempts[$id] = (int) data_get($attempts, $id, 0) + 1;

        if ($attempts[$id] < self::MAX_ATTEMPTS) {
            return false;
        }

        unset($attempts[$id]);
        RedactedError::report($exception);

        return true;
    }

    /**
     * The feed cursor, carrying the failed-read counts of the ids still in
     * its backlog.
     *
     * @param  array{notifications: mixed, statuses: mixed, backlog: list<string>}  $cursor
     * @param  array<string, int>  $attempts
     */
    private static function feedCursor(array $cursor, array $attempts): string
    {
        $attempts = array_intersect_key($attempts, array_flip($cursor['backlog']));

        return (string) json_encode($attempts === [] ? $cursor : [...$cursor, 'attempts' => $attempts]);
    }

    /**
     * Who a private mention reaches: its author and the accounts it mentions.
     * A status of the thread that also reaches someone else is not part of
     * the 1:1 conversation.
     *
     * @param  array<string, mixed>  $status
     * @return list<string>
     */
    private static function peopleOf(array $status): array
    {
        return collect([(string) data_get($status, 'account.id'), ...collect((array) data_get($status, 'mentions', []))->map(fn (mixed $mention): string => (string) data_get($mention, 'id'))])
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $participant
     * @param  array<string, mixed>  $status
     */
    private function directStatus(SocialAccount $account, string $conversationId, array $participant, array $status): IncomingMessage
    {
        $accountId = (string) data_get($status, 'account.id');

        return new IncomingMessage(
            kind: ConversationKind::DirectMessage,
            subject: new IncomingSubject(
                platformId: $conversationId,
                text: null,
                media: [],
                url: null,
                publishedAt: null,
                remoteId: null,
                participantName: data_get($participant, 'display_name') ?: data_get($participant, 'acct'),
                participantUsername: data_get($participant, 'acct'),
                participantAvatarUrl: data_get($participant, 'avatar'),
            ),
            platformMessageId: (string) data_get($status, 'id'),
            platformParentId: null,
            direction: $accountId === $account->platform_user_id ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: $accountId,
            authorName: data_get($status, 'account.display_name') ?: data_get($status, 'account.acct'),
            authorUsername: data_get($status, 'account.acct'),
            authorAvatarUrl: data_get($status, 'account.avatar'),
            body: $this->plainText((string) data_get($status, 'content')),
            media: $this->media((array) data_get($status, 'media_attachments', [])),
            platformUrl: data_get($status, 'url') ?? data_get($status, 'uri'),
            platformCreatedAt: CarbonImmutable::parse((string) data_get($status, 'created_at')),
        );
    }

    /**
     * A private mention answering the conversation's latest status.
     */
    public function sendDirectMessage(SocialAccount $account, InboxConversation $conversation, string $text): SentReply
    {
        $latest = $conversation->messages()->whereNotNull('platform_message_id')->whereNull('removed_at')->orderByDesc('platform_created_at')->first();

        $response = $this->socialHttp()->withToken($account->access_token)
            ->post("{$account->mastodonInstance()}/api/v1/statuses", array_filter([
                'status' => $text,
                'visibility' => 'direct',
                'in_reply_to_id' => $latest?->platform_message_id,
            ], fn (mixed $value): bool => filled($value)));

        if ($response->failed()) {
            throw MastodonPublishException::fromApiResponse($response);
        }

        return new SentReply(
            platformMessageId: (string) $response->json('id'),
            platformUrl: $response->json('url'),
            platformCreatedAt: CarbonImmutable::parse((string) ($response->json('created_at') ?? now()->toIso8601String())),
        );
    }

    /**
     * A private mention reaches only the accounts it mentions, so the
     * participant's handle leads the text unless the user already wrote it.
     */
    public function directMessageText(InboxConversation $conversation, string $text): string
    {
        $handle = ltrim((string) $conversation->participant_username, '@');

        if ($handle === '') {
            return $text;
        }

        $mentioned = preg_match('/(?<![\w@])@'.preg_quote($handle, '/').'(?![\w@-]|\.\w)/iu', $text) === 1;

        return $mentioned ? $text : "@{$handle} {$text}";
    }

    public function directMessageLimit(SocialAccount $account): MessageLimit
    {
        return new MessageLimit($account->maxContentLength(), ReplyLengthUnit::Characters);
    }

    public function hasReplyWindow(): bool
    {
        return false;
    }

    public function replyWindowEndsAt(?CarbonImmutable $lastInboundAt): ?CarbonImmutable
    {
        return null;
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

    /**
     * Pages a newest-first feed: forward from the cursor with min_id on a poll
     * (each page is the one right after min_id, so the newest id read is on the
     * last page), back to $since with max_id on a backfill.
     *
     * @see https://docs.joinmastodon.org/api/guidelines/#pagination
     *
     * @param  array<string, mixed>  $query
     * @return array{0: Collection<int, array<string, mixed>>, 1: ?string}
     */
    private function feed(SocialAccount $account, string $url, array $query, ?string $cursor, CarbonImmutable $since): array
    {
        $items = collect();
        $newest = null;
        $minId = $cursor;
        $maxId = null;

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $page = collect((array) $this->get($account, $url, [...$query, 'min_id' => $minId, 'max_id' => $maxId]));

            if ($page->isEmpty()) {
                break;
            }

            $isLastPage = $page->count() < (int) data_get($query, 'limit');

            if (filled($cursor)) {
                $items = $items->concat($page);
                $minId = (string) data_get($page->first(), 'id');
                $newest = $minId;

                if ($isLastPage) {
                    break;
                }

                continue;
            }

            $newest ??= (string) data_get($page->first(), 'id');
            $fresh = $page->filter(fn (array $item): bool => CarbonImmutable::parse((string) data_get($item, 'created_at'))->gte($since));
            $items = $items->concat($fresh);
            $maxId = (string) data_get($page->last(), 'id');

            if ($fresh->count() < $page->count() || $isLastPage) {
                break;
            }
        }

        return [$items->values(), $newest];
    }

    /** @return array<string, mixed> */
    private function context(SocialAccount $account, string $instance, string $statusId): array
    {
        return (array) $this->get($account, "{$instance}/api/v1/statuses/{$statusId}/context", []);
    }

    /**
     * The `types[]` key is sent as written (types%5B%5D=mention), the form Rails reads as an array.
     *
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     */
    private function get(SocialAccount $account, string $url, array $query): array
    {
        $response = $this->socialHttp()->withToken($account->access_token)
            ->get($url, array_filter($query, fn (mixed $value): bool => filled($value)));

        if ($response->failed()) {
            throw MastodonPublishException::fromApiResponse($response);
        }

        return (array) $response->json();
    }

    /** @param array<string, mixed> $root */
    private function subject(array $root): IncomingSubject
    {
        return new IncomingSubject(
            platformId: (string) data_get($root, 'id'),
            text: $this->plainText((string) data_get($root, 'content')),
            media: $this->media((array) data_get($root, 'media_attachments', [])),
            url: data_get($root, 'url') ?? data_get($root, 'uri'),
            publishedAt: CarbonImmutable::parse((string) data_get($root, 'created_at')),
            remoteId: (string) data_get($root, 'id'),
        );
    }

    /** @param array<string, mixed> $status */
    private function toMessage(SocialAccount $account, array $status, IncomingSubject $subject, string $rootId): IncomingMessage
    {
        $parentId = (string) data_get($status, 'in_reply_to_id');
        $accountId = (string) data_get($status, 'account.id');

        return new IncomingMessage(
            kind: ConversationKind::Comment,
            subject: $subject,
            platformMessageId: (string) data_get($status, 'id'),
            platformParentId: $parentId === $rootId ? null : $parentId,
            direction: $accountId === $account->platform_user_id ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: $accountId,
            authorName: data_get($status, 'account.display_name') ?: data_get($status, 'account.username'),
            authorUsername: data_get($status, 'account.acct'),
            authorAvatarUrl: data_get($status, 'account.avatar'),
            body: $this->plainText((string) data_get($status, 'content')),
            media: $this->media((array) data_get($status, 'media_attachments', [])),
            platformUrl: data_get($status, 'url') ?? data_get($status, 'uri'),
            platformCreatedAt: CarbonImmutable::parse((string) data_get($status, 'created_at')),
            meta: array_filter([
                'visibility' => data_get($status, 'visibility'),
                'spoiler_text' => data_get($status, 'spoiler_text'),
            ], fn (mixed $value): bool => filled($value)),
        );
    }

    /**
     * @param  array<int, mixed>  $attachments
     * @return list<array{type: string, url: string, thumbnail_url?: string|null}>
     */
    private function media(array $attachments): array
    {
        return collect($attachments)
            ->filter(fn (mixed $attachment): bool => in_array(data_get($attachment, 'type'), ['image', 'video', 'gifv'], true))
            ->map(fn (array $attachment): array => [
                'type' => data_get($attachment, 'type') === 'image' ? 'image' : 'video',
                'url' => (string) data_get($attachment, 'url'),
                'thumbnail_url' => data_get($attachment, 'preview_url'),
            ])
            ->values()
            ->all();
    }

    private function plainText(string $html): string
    {
        $text = preg_replace(['/<br\s*\/?>/i', '/<\/p>\s*<p>/i'], ["\n", "\n\n"], $html) ?? $html;

        return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5));
    }
}
