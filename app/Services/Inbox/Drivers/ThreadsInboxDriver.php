<?php

declare(strict_types=1);

namespace App\Services\Inbox\Drivers;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Inbox\InboxWebhookEvents;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\Social\ThreadsPublishException;
use App\Models\AnalyticsPublication;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\MetaGraphRequests;
use App\Services\Inbox\Concerns\ScansRecentPublications;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\Contracts\ReceivesInboxWebhooks;
use App\Services\Social\ThreadsPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

/**
 * Replies to the channel's Threads posts. Webhooks are subscribed in the App
 * Dashboard only, so there is nothing to subscribe per account.
 *
 * @see https://developers.facebook.com/docs/threads/webhooks
 * @see https://developers.facebook.com/documentation/threads/retrieve-and-manage-replies/replies-and-conversations.md
 * @see https://developers.facebook.com/docs/threads/retrieve-and-manage-replies
 */
class ThreadsInboxDriver implements InboxDriver, ReceivesInboxWebhooks
{
    use MetaGraphRequests, ScansRecentPublications;

    private const string REPLY_FIELDS = 'id,text,username,permalink,timestamp,replied_to,root_post,is_reply_owned_by_me,profile_picture_url';

    private const int MAX_CONVERSATION_PAGES = 10;

    private const string MENTION_FIELDS = 'id,text,username,permalink,timestamp,media_type,media_url,thumbnail_url,is_reply,root_post,replied_to,profile_picture_url';

    /** The earliest `since` the mentions edge accepts. */
    private const int MENTIONS_SINCE_MINIMUM = 1688540400;

    private const int MAX_MENTION_PAGES = 10;

    /** @return list<ConversationKind> */
    public function kinds(): array
    {
        return [ConversationKind::Comment, ConversationKind::Mention];
    }

    public function poll(SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): PollResult
    {
        if ($kind === ConversationKind::Mention) {
            return $this->pollMentions($account, $cursor, $since);
        }

        if ($kind !== ConversationKind::Comment) {
            return new PollResult([], $cursor);
        }

        $graph = config('trypost.platforms.threads.graph_api');

        return $this->scanPublications($account, $cursor, $since, function (DiscoveredPublication $publication) use ($graph, $account): array {
            $subject = $this->subjectFrom($publication);
            $messages = [];
            $after = null;

            for ($page = 0; $page < self::MAX_CONVERSATION_PAGES; $page++) {
                $body = $this->graphGet($account, "{$graph}/{$publication->providerPostId}/conversation", [
                    'fields' => self::REPLY_FIELDS,
                    'after' => $after,
                ]);

                foreach ((array) data_get($body, 'data', []) as $reply) {
                    $messages[] = $this->toMessage(
                        $subject,
                        $reply,
                        $publication->providerPostId,
                        data_get($reply, 'is_reply_owned_by_me') === true,
                    );
                }

                $after = filled(data_get($body, 'paging.next')) ? data_get($body, 'paging.cursors.after') : null;

                if (blank($after)) {
                    break;
                }
            }

            return $messages;
        });
    }

    /**
     * One `replies` value of a Threads webhook delivery.
     *
     * @param  array<string, mixed>  $value
     */
    public function webhookEvents(SocialAccount $account, array $value): InboxWebhookEvents
    {
        $rootId = (string) data_get($value, 'root_post.id');

        if (blank(data_get($value, 'id')) || blank($rootId)) {
            return new InboxWebhookEvents;
        }

        if ((string) data_get($value, 'root_post.owner_id') !== (string) $account->platform_user_id) {
            return new InboxWebhookEvents(self::answersStoredMessage($account, $value)
                ? [$this->toMentionMessage($account, $value, new IncomingSubject($rootId, null, [], null, null, null), (string) data_get($value, 'replied_to.id'))]
                : []);
        }

        return new InboxWebhookEvents([$this->toMessage(
            $this->webhookSubject($rootId),
            $value,
            $rootId,
            filled($account->username) && data_get($value, 'username') === $account->username,
        )]);
    }

    /**
     * One `mentions` value of a Threads webhook delivery. A mention in a reply
     * on the channel's own post is a comment, delivered by `replies`.
     *
     * @see https://developers.facebook.com/documentation/threads/webhooks.md
     *
     * @param  array<string, mixed>  $value
     */
    public function mentionWebhookEvents(SocialAccount $account, array $value): InboxWebhookEvents
    {
        if (blank(data_get($value, 'id')) || $this->isOnOwnPost($account, $value)) {
            return new InboxWebhookEvents;
        }

        return new InboxWebhookEvents([$this->toMentionMessage($account, $value)]);
    }

    /**
     * Whether a reply on someone else's post answers a message this channel
     * keeps (its answer under a mention).
     *
     * @param  array<string, mixed>  $value
     */
    public static function answersStoredMessage(SocialAccount $account, array $value): bool
    {
        $parentId = (string) data_get($value, 'replied_to.id');

        return filled($parentId) && InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->where('platform_message_hash', InboxConversation::hashOf($parentId))
            ->exists();
    }

    /**
     * @see https://developers.facebook.com/documentation/threads/threads-mentions.md
     */
    private function pollMentions(SocialAccount $account, ?string $cursor, CarbonImmutable $since): PollResult
    {
        $graph = config('trypost.platforms.threads.graph_api');
        $from = max(self::MENTIONS_SINCE_MINIMUM, filled($cursor) ? (int) $cursor : $since->getTimestamp());
        $newest = filled($cursor) ? (int) $cursor : null;
        $messages = [];
        $after = null;

        for ($page = 0; $page < self::MAX_MENTION_PAGES; $page++) {
            $body = $this->graphGet($account, "{$graph}/{$account->platform_user_id}/mentions", [
                'fields' => self::MENTION_FIELDS,
                'since' => $from,
                'until' => now()->getTimestamp(),
                'after' => $after,
            ]);
            $items = (array) data_get($body, 'data', []);

            foreach ($items as $media) {
                $timestamp = filled(data_get($media, 'timestamp')) ? CarbonImmutable::parse((string) data_get($media, 'timestamp'))->getTimestamp() : null;
                $newest = max($newest ?? 0, $timestamp ?? 0) ?: $newest;

                if (filled(data_get($media, 'id')) && ! $this->isOnOwnPost($account, $media)) {
                    $messages[] = $this->toMentionMessage($account, $media);
                }
            }

            $after = $items !== [] && filled(data_get($body, 'paging.next')) ? data_get($body, 'paging.cursors.after') : null;

            if (blank($after)) {
                break;
            }
        }

        return new PollResult($messages, filled($newest) ? (string) $newest : $cursor);
    }

    /** @param array<string, mixed> $media */
    private function isOnOwnPost(SocialAccount $account, array $media): bool
    {
        $rootId = (string) data_get($media, 'root_post.id');

        if (data_get($media, 'is_reply') !== true || blank($rootId)) {
            return false;
        }

        if (filled(data_get($media, 'root_post.owner_id'))) {
            return (string) data_get($media, 'root_post.owner_id') === (string) $account->platform_user_id;
        }

        return InboxConversation::query()
            ->where('social_account_id', $account->id)
            ->where('kind', ConversationKind::Comment)
            ->where('platform_conversation_hash', InboxConversation::hashOf($rootId))
            ->exists()
            || AnalyticsPublication::query()->where('social_account_id', $account->id)->where('remote_id', $rootId)->exists();
    }

    /** @param array<string, mixed> $media */
    private function toMentionMessage(SocialAccount $account, array $media, ?IncomingSubject $subject = null, ?string $parentId = null): IncomingMessage
    {
        $username = data_get($media, 'username');
        $timestamp = filled(data_get($media, 'timestamp')) ? CarbonImmutable::parse((string) data_get($media, 'timestamp')) : CarbonImmutable::now();
        $thumbnail = data_get($media, 'thumbnail_url') ?: data_get($media, 'media_url');
        $own = filled($account->username) && $username === $account->username;
        $parentId ??= self::answersStoredMessage($account, $media) ? (string) data_get($media, 'replied_to.id') : null;

        return new IncomingMessage(
            kind: ConversationKind::Mention,
            subject: $subject ?? new IncomingSubject(
                platformId: (string) data_get($media, 'id'),
                text: data_get($media, 'text'),
                media: filled($thumbnail) ? [['type' => 'image', 'url' => (string) $thumbnail]] : [],
                url: data_get($media, 'permalink'),
                publishedAt: $timestamp,
                remoteId: null,
                participantName: $username,
                participantUsername: $username,
                participantAvatarUrl: data_get($media, 'profile_picture_url'),
            ),
            platformMessageId: (string) data_get($media, 'id'),
            platformParentId: filled($parentId) ? $parentId : null,
            direction: $own ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: null,
            authorName: $username,
            authorUsername: $username,
            authorAvatarUrl: data_get($media, 'profile_picture_url'),
            body: data_get($media, 'text'),
            media: [],
            platformUrl: data_get($media, 'permalink'),
            platformCreatedAt: $timestamp,
        );
    }

    public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
    {
        $graph = config('trypost.platforms.threads.graph_api');
        $limit = $this->graphGet($account, "{$graph}/{$account->platform_user_id}/threads_publishing_limit", [
            'fields' => 'reply_quota_usage,reply_config',
        ]);
        $quota = (array) (data_get($limit, 'data.0') ?? $limit);
        $total = data_get($quota, 'reply_config.quota_total');

        if (filled($total) && (int) data_get($quota, 'reply_quota_usage', 0) >= (int) $total) {
            throw new ThreadsPublishException(userMessage: 'Threads reply limit reached for today.', category: ErrorCategory::RateLimit);
        }

        $published = app(ThreadsPublisher::class)->replyTo($account, $parent->platform_message_id, $text);

        return new SentReply(
            platformMessageId: (string) data_get($published, 'id'),
            platformUrl: data_get($published, 'url'),
            platformCreatedAt: CarbonImmutable::now(),
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

    public function subscribeWebhooks(SocialAccount $account): bool
    {
        return true;
    }

    protected function graphException(Response $response): SocialPublishException
    {
        return ThreadsPublishException::fromApiResponse($response);
    }

    /**
     * A comment's post is read when it is imported (ImportInboxPost), never
     * per delivery: the subject only names it.
     */
    private function webhookSubject(string $rootId): IncomingSubject
    {
        return new IncomingSubject($rootId, null, [], null, null, $rootId);
    }

    /** @param array<string, mixed> $reply */
    private function toMessage(IncomingSubject $subject, array $reply, string $rootId, bool $own): IncomingMessage
    {
        $parentId = (string) data_get($reply, 'replied_to.id');
        $username = data_get($reply, 'username');
        $timestamp = data_get($reply, 'timestamp');

        return new IncomingMessage(
            kind: ConversationKind::Comment,
            subject: $subject,
            platformMessageId: (string) data_get($reply, 'id'),
            platformParentId: filled($parentId) && $parentId !== $rootId ? $parentId : null,
            direction: $own ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: null,
            authorName: $username,
            authorUsername: $username,
            authorAvatarUrl: data_get($reply, 'profile_picture_url'),
            body: data_get($reply, 'text'),
            media: [],
            platformUrl: data_get($reply, 'permalink'),
            platformCreatedAt: filled($timestamp) ? CarbonImmutable::parse((string) $timestamp) : CarbonImmutable::now(),
        );
    }
}
