<?php

declare(strict_types=1);

namespace App\Services\Inbox\Drivers;

use App\Dto\Analytics\DiscoveredPublication;
use App\Dto\Inbox\InboxWebhookEvents;
use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\ResumableCursor;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\AnalyticsPublication;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\MetaGraphRequests;
use App\Services\Inbox\Concerns\ScansRecentPublications;
use App\Services\Inbox\Concerns\SendsMetaDirectMessages;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\Contracts\ReceivesInboxWebhooks;
use App\Services\Inbox\Meta\MetaDirectMessages;
use App\Services\Inbox\PollBudget;
use App\Services\Social\Meta\GraphError;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Comments on the channel's Instagram posts and its direct messages, for both
 * connection methods.
 *
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-media/comments
 * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-comment/replies
 * @see https://developers.facebook.com/docs/instagram-platform/webhooks
 */
class InstagramInboxDriver implements DirectMessageDriver, InboxDriver, ReceivesInboxWebhooks
{
    use MetaGraphRequests, ScansRecentPublications, SendsMetaDirectMessages;

    private const string COMMENT_FIELDS = 'id,text,timestamp,from{id,username},username,parent_id,hidden';

    private const int PAGE_SIZE = 50;

    private const int MAX_COMMENT_PAGES = 10;

    private const int MAX_REPLY_PAGES = 10;

    private const int MAX_TAG_PAGES = 5;

    private const string TAG_FIELDS = 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username';

    /** @return list<ConversationKind> */
    public function kinds(): array
    {
        return [ConversationKind::Comment, ConversationKind::Mention, ConversationKind::DirectMessage];
    }

    public function poll(SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): PollResult
    {
        if ($kind === ConversationKind::DirectMessage) {
            return app(MetaDirectMessages::class)->poll($account, $cursor, $since);
        }

        if ($kind === ConversationKind::Mention) {
            return $account->platform === Platform::InstagramFacebook ? $this->pollTags($account, $cursor, $since) : new PollResult([], $cursor);
        }

        if ($kind !== ConversationKind::Comment) {
            return new PollResult([], $cursor);
        }

        $base = $account->platform->instagramGraphBaseUrl();
        $fields = self::COMMENT_FIELDS;
        $pageSize = self::PAGE_SIZE;

        return $this->scanPublications($account, $cursor, $since, function (DiscoveredPublication $publication) use ($account, $base, $fields, $pageSize): array {
            $subject = $this->subjectFrom($publication);
            $comments = [];
            $after = null;

            for ($page = 0; $page < self::MAX_COMMENT_PAGES; $page++) {
                $body = $this->graphGet($account, "{$base}/{$publication->providerPostId}/comments", [
                    'fields' => "{$fields},replies.limit({$pageSize}){{$fields}}",
                    'limit' => $pageSize,
                    'after' => $after,
                ]);

                foreach ((array) data_get($body, 'data', []) as $comment) {
                    $comments = self::mergeComment($comments, $comment, data_get($comment, 'parent_id'));

                    foreach ($this->replies($account, $base, $comment) as $reply) {
                        $comments = self::mergeComment($comments, $reply, data_get($comment, 'id'));
                    }
                }

                $after = filled(data_get($body, 'paging.next')) ? data_get($body, 'paging.cursors.after') : null;

                if (blank($after)) {
                    break;
                }
            }

            return array_values(array_map(
                fn (array $comment): IncomingMessage => $this->toMessage($account, $subject, $comment, data_get($comment, 'parent_id'), CarbonImmutable::parse((string) data_get($comment, 'timestamp'))),
                $comments,
            ));
        });
    }

    /**
     * The media other accounts tagged the channel in (photo tags), newest
     * first, read as mentions. The edge is documented for Facebook Login only
     * and takes no time filter, so media older than the watermark end the
     * read; it returns cursors without `next`, so a page continues while it
     * carries an `after` cursor.
     *
     * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/tags
     */
    private function pollTags(SocialAccount $account, ?string $cursor, CarbonImmutable $since): PollResult
    {
        $place = ResumableCursor::from($cursor);
        $stopAt = $place->stopAt($since);
        $messages = [];
        $newest = $place->newest;
        $after = $place->after;

        for ($page = 0; $page < self::MAX_TAG_PAGES; $page++) {
            $body = $this->graphGet($account, "{$account->platform->instagramGraphBaseUrl()}/{$account->platform_user_id}/tags", [
                'fields' => self::TAG_FIELDS,
                'limit' => self::PAGE_SIZE,
                'after' => $after,
            ]);
            $listed = (array) data_get($body, 'data', []);
            $fresh = collect($listed)->filter(fn (array $media): bool => filled(data_get($media, 'timestamp')) && CarbonImmutable::parse((string) data_get($media, 'timestamp'))->gt($stopAt));

            foreach ($fresh as $media) {
                $mediaId = (string) data_get($media, 'id');
                $taggedAt = CarbonImmutable::parse((string) data_get($media, 'timestamp'));
                $newest = $newest === null || $taggedAt->gt($newest) ? $taggedAt : $newest;

                if ($mediaId !== '') {
                    $messages[] = $this->mediaMention($account, $this->mentionSubject($mediaId, $media), $media, ['media_id' => $mediaId, 'tagged' => true]);
                }
            }

            $after = $listed !== [] && $fresh->count() === count($listed) ? data_get($body, 'paging.cursors.after') : null;

            if (blank($after) || app(PollBudget::class)->exhausted()) {
                break;
            }
        }

        return new PollResult($messages, $place->next($after, $newest), partial: filled($after));
    }

    /**
     * Media that mentions the channel in its caption or tags it, kept as the
     * mention itself.
     *
     * @param  array<string, mixed>  $media
     * @param  array<string, mixed>  $meta
     */
    private function mediaMention(SocialAccount $account, IncomingSubject $subject, array $media, array $meta): IncomingMessage
    {
        return new IncomingMessage(
            kind: ConversationKind::Mention,
            subject: $subject,
            platformMessageId: $subject->platformId,
            platformParentId: null,
            direction: MessageDirection::Inbound,
            authorPlatformId: null,
            authorName: data_get($media, 'username'),
            authorUsername: data_get($media, 'username'),
            authorAvatarUrl: $this->authorAvatar($account, data_get($media, 'username')),
            body: data_get($media, 'caption'),
            media: $subject->media,
            platformUrl: $subject->url,
            platformCreatedAt: $subject->publishedAt ?? CarbonImmutable::now(),
            meta: $meta,
        );
    }

    /**
     * The replies embedded in a comment, then the pages after them.
     *
     * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-comment/replies
     *
     * @param  array<string, mixed>  $comment
     * @return list<array<string, mixed>>
     */
    /**
     * Instagram lists a reply twice: at the top of the media's comments, with
     * its author but no parent, and under its comment, with the parent but
     * without its author. Both reads are one comment, so the fields each copy
     * carries are kept together under its id.
     *
     * @param  array<string, array<string, mixed>>  $comments
     * @param  array<string, mixed>  $comment
     * @return array<string, array<string, mixed>>
     */
    private static function mergeComment(array $comments, array $comment, mixed $parentId): array
    {
        $id = (string) data_get($comment, 'id');
        $known = data_get($comments, $id, []);
        $merged = [...$known, ...array_filter($comment, fn (mixed $value): bool => filled($value))];

        if (filled($parentId)) {
            $merged['parent_id'] = (string) $parentId;
        }

        $comments[$id] = $merged;

        return $comments;
    }

    private function replies(SocialAccount $account, string $base, array $comment): array
    {
        $commentId = (string) data_get($comment, 'id');
        $replies = array_values((array) data_get($comment, 'replies.data', []));
        $after = filled(data_get($comment, 'replies.paging.next')) ? data_get($comment, 'replies.paging.cursors.after') : null;

        for ($page = 0; $page < self::MAX_REPLY_PAGES && filled($after); $page++) {
            $body = $this->graphGet($account, "{$base}/{$commentId}/replies", [
                'fields' => self::COMMENT_FIELDS,
                'limit' => self::PAGE_SIZE,
                'after' => $after,
            ]);

            $replies = [...$replies, ...array_values((array) data_get($body, 'data', []))];
            $after = filled(data_get($body, 'paging.next')) ? data_get($body, 'paging.cursors.after') : null;
        }

        return $replies;
    }

    /**
     * One `comments` change of an Instagram webhook delivery. The payload
     * carries no comment time, so the entry time is stored, and it stays:
     * a later poll never moves a stored message's time.
     *
     * @param  array<string, mixed>  $value
     */
    public function webhookEvents(SocialAccount $account, array $value, int $time): InboxWebhookEvents
    {
        $mediaId = (string) data_get($value, 'media.id');

        if (blank($mediaId) || blank(data_get($value, 'id'))) {
            return new InboxWebhookEvents;
        }

        if ($account->platform === Platform::Instagram && ! $this->ownsMedia($account, $mediaId)) {
            return new InboxWebhookEvents([$this->foreignCommentMention($account, $value, $mediaId, CarbonImmutable::createFromTimestamp($time))]);
        }

        return new InboxWebhookEvents([
            $this->toMessage(
                $account,
                $this->webhookSubject($mediaId),
                $value,
                data_get($value, 'parent_id'),
                CarbonImmutable::createFromTimestamp($time),
            ),
        ]);
    }

    /**
     * One `mentions` change (Facebook Login): a caption mention carries the
     * media id, a comment mention the comment id too. Neither carries the
     * content, so it is read back; one that cannot be read (comments turned
     * off, media gone) cannot be answered and is dropped.
     *
     * @see https://developers.facebook.com/documentation/instagram-platform/instagram-graph-api/reference/ig-user/mentioned_media.md
     * @see https://developers.facebook.com/documentation/instagram-platform/instagram-graph-api/reference/ig-user/mentioned_comment.md
     *
     * @param  array<string, mixed>  $value
     */
    public function mentionWebhookEvents(SocialAccount $account, array $value): InboxWebhookEvents
    {
        $mediaId = (string) data_get($value, 'media_id');
        $commentId = (string) data_get($value, 'comment_id');

        if ($mediaId === '') {
            return new InboxWebhookEvents;
        }

        $url = "{$account->platform->instagramGraphBaseUrl()}/{$account->instagramAccountId()}";

        try {
            $body = $commentId !== ''
                ? $this->graphGet($account, $url, ['fields' => "mentioned_comment.comment_id({$commentId}){id,text,timestamp,from{id,username},media{id,caption,media_url,thumbnail_url,permalink,username,timestamp}}"])
                : $this->graphGet($account, $url, ['fields' => "mentioned_media.media_id({$mediaId}){id,caption,media_type,media_url,thumbnail_url,timestamp,username}"]);
        } catch (InstagramPublishException) {
            return new InboxWebhookEvents;
        }

        $media = $commentId !== '' ? (array) data_get($body, 'mentioned_comment.media', []) : (array) data_get($body, 'mentioned_media', []);
        $subject = $this->mentionSubject($mediaId, $media);

        if ($commentId === '') {
            return new InboxWebhookEvents([$this->mediaMention($account, $subject, $media, ['media_id' => $mediaId])]);
        }

        $comment = (array) data_get($body, 'mentioned_comment', []);
        $username = data_get($comment, 'from.username');

        return new InboxWebhookEvents([new IncomingMessage(
            kind: ConversationKind::Mention,
            subject: $subject,
            platformMessageId: $commentId,
            platformParentId: null,
            direction: MessageDirection::Inbound,
            authorPlatformId: transform(data_get($comment, 'from.id'), fn (mixed $id): string => (string) $id),
            authorName: $username,
            authorUsername: $username,
            authorAvatarUrl: $this->authorAvatar($account, $username),
            body: data_get($comment, 'text'),
            media: [],
            platformUrl: $subject->url,
            platformCreatedAt: filled(data_get($comment, 'timestamp')) ? CarbonImmutable::parse((string) data_get($comment, 'timestamp')) : CarbonImmutable::now(),
            meta: ['media_id' => $mediaId, 'comment_id' => $commentId],
        )]);
    }

    /**
     * A reply in a mention goes to the mentions edge, the only way to answer
     * on media the channel does not own.
     *
     * @see https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/mentions
     * @see https://developers.facebook.com/documentation/instagram-platform/instagram-api-with-instagram-login/mentions.md
     */
    public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
    {
        $root = filled($parent->root_id) ? InboxMessage::query()->findOrFail($parent->root_id) : $parent;

        if ($parent->conversation?->kind === ConversationKind::Mention) {
            $body = $this->graphPost($account, "{$account->platform->instagramGraphBaseUrl()}/{$account->instagramAccountId()}/mentions", [
                'media_id' => (string) (data_get($root->meta, 'media_id') ?: $parent->conversation->platform_conversation_id),
                'comment_id' => data_get($root->meta, 'comment_id'),
                'message' => $text,
            ]);

            return new SentReply(
                platformMessageId: (string) data_get($body, 'id'),
                platformUrl: null,
                platformCreatedAt: CarbonImmutable::now(),
            );
        }

        $body = $this->graphPost($account, "{$account->platform->instagramGraphBaseUrl()}/{$root->platform_message_id}/replies", ['message' => $text]);

        return new SentReply(
            platformMessageId: (string) data_get($body, 'id'),
            platformUrl: null,
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

    /**
     * A Page's subscribed_fields cannot carry Instagram fields: `comments`,
     * `mentions` and `messages` come from the app's `instagram` subscription,
     * and subscribing the Page (with `feed`) installs the app on it.
     *
     * @see https://developers.facebook.com/docs/graph-api/reference/page/subscribed_apps/
     * @see https://developers.facebook.com/docs/instagram-platform/webhooks
     */
    public function subscribeWebhooks(SocialAccount $account): bool
    {
        if ($account->platform === Platform::InstagramFacebook) {
            $graph = config('trypost.platforms.facebook.graph_api');
            $pageId = data_get($account->meta, 'page_id');
            $body = $this->subscribePageFields($account, $graph, (string) $pageId, ['feed']);
        } else {
            $graph = config('trypost.platforms.instagram.graph_api');
            $body = $this->graphPost($account, "{$graph}/me/subscribed_apps", [
                'subscribed_fields' => implode(',', ['comments', 'live_comments', ...$this->directMessageWebhookFields($account, 'messages')]),
            ]);
        }

        if (data_get($body, 'success') !== true) {
            throw new InstagramPublishException(userMessage: 'Instagram did not accept the webhook subscription.', category: ErrorCategory::Unknown);
        }

        return true;
    }

    protected function graphException(Response $response): SocialPublishException
    {
        return InstagramPublishException::fromApiResponse($response);
    }

    /**
     * Instagram Login has no mention event: a mention arrives as a comment on
     * media the channel does not own. Instagram returns the media `owner` only
     * to the account that created it, so media read without the channel as its
     * owner, that it may not read or that does not exist for it is not the
     * channel's; any other error propagates, so nothing is filed on a guess.
     *
     * @see https://developers.facebook.com/docs/instagram-platform/reference/instagram-media/
     */
    private function ownsMedia(SocialAccount $account, string $mediaId): bool
    {
        if (InboxConversation::knows($account, ConversationKind::Comment, $mediaId)
            || AnalyticsPublication::query()->where('social_account_id', $account->id)->where('remote_id', $mediaId)->exists()
            || Post::query()->where('social_account_id', $account->id)->where('platform_post_id', $mediaId)->exists()) {
            return true;
        }

        try {
            $media = $this->graphGet($account, "{$account->platform->instagramGraphBaseUrl()}/{$mediaId}", ['fields' => 'id,owner{id}']);
        } catch (InstagramPublishException $exception) {
            if (self::isNotTheChannels($exception)) {
                return false;
            }

            throw $exception;
        }

        $ownerId = (string) data_get($media, 'owner.id');
        $accountId = $account->instagramAccountId();

        return $ownerId !== '' && (blank($accountId) || $ownerId === $accountId);
    }

    /**
     * The channel may not read the media (permission errors 10 and 200-299),
     * or the media does not exist for it (100 with subcode 33), so it is not
     * the channel's.
     *
     * @see https://developers.facebook.com/docs/graph-api/guides/error-handling/
     */
    private static function isNotTheChannels(InstagramPublishException $exception): bool
    {
        $body = json_decode((string) $exception->rawResponse, true);

        return GraphError::isPermissionDenied(is_array($body) ? $body : null)
            || ((int) data_get($body, 'error.code') === 100 && (int) data_get($body, 'error.error_subcode') === 33);
    }

    /** @param array<string, mixed> $value */
    private function foreignCommentMention(SocialAccount $account, array $value, string $mediaId, CarbonImmutable $createdAt): IncomingMessage
    {
        $username = data_get($value, 'from.username');

        return new IncomingMessage(
            kind: ConversationKind::Mention,
            subject: new IncomingSubject(
                platformId: $mediaId,
                text: null,
                media: [],
                url: null,
                publishedAt: null,
                remoteId: null,
                participantName: $username,
                participantUsername: $username,
            ),
            platformMessageId: (string) data_get($value, 'id'),
            platformParentId: filled(data_get($value, 'parent_id')) ? (string) data_get($value, 'parent_id') : null,
            direction: $this->isOwn($account, $value) ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: transform(data_get($value, 'from.id'), fn (mixed $id): string => (string) $id),
            authorName: $username,
            authorUsername: $username,
            authorAvatarUrl: $this->isOwn($account, $value) ? null : $this->authorAvatar($account, $username),
            body: data_get($value, 'text'),
            media: [],
            platformUrl: null,
            platformCreatedAt: $createdAt,
            meta: ['media_id' => $mediaId, 'comment_id' => (string) data_get($value, 'id')],
        );
    }

    /** @param array<string, mixed> $media */
    private function mentionSubject(string $mediaId, array $media): IncomingSubject
    {
        $thumbnail = data_get($media, 'thumbnail_url') ?: data_get($media, 'media_url');
        $username = data_get($media, 'username');

        return new IncomingSubject(
            platformId: $mediaId,
            text: data_get($media, 'caption'),
            media: filled($thumbnail) ? [['type' => 'image', 'url' => (string) $thumbnail]] : [],
            url: data_get($media, 'permalink'),
            publishedAt: filled(data_get($media, 'timestamp')) ? CarbonImmutable::parse((string) data_get($media, 'timestamp')) : null,
            remoteId: null,
            participantName: $username,
            participantUsername: $username,
        );
    }

    /**
     * A comment's post is read when it is imported (ImportInboxPost), never
     * per delivery: the subject only names it.
     */
    private function webhookSubject(string $mediaId): IncomingSubject
    {
        return new IncomingSubject($mediaId, null, [], null, null, $mediaId);
    }

    /** @param array<string, mixed> $comment */
    private function toMessage(SocialAccount $account, IncomingSubject $subject, array $comment, ?string $parentId, CarbonImmutable $createdAt): IncomingMessage
    {
        $username = (string) (data_get($comment, 'from.username') ?? data_get($comment, 'username'));

        return new IncomingMessage(
            kind: ConversationKind::Comment,
            subject: $subject,
            platformMessageId: (string) data_get($comment, 'id'),
            platformParentId: filled($parentId) ? (string) $parentId : null,
            direction: $this->isOwn($account, $comment) ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: transform(data_get($comment, 'from.id'), fn (mixed $id): string => (string) $id),
            authorName: $username ?: null,
            authorUsername: $username ?: null,
            authorAvatarUrl: $this->isOwn($account, $comment) ? null : $this->authorAvatar($account, $username),
            body: data_get($comment, 'text'),
            media: [],
            platformUrl: $subject->url,
            platformCreatedAt: $createdAt,
            hidden: array_key_exists('hidden', $comment) ? (bool) data_get($comment, 'hidden') : null,
        );
    }

    /**
     * A commenter's picture, for a channel connected through Facebook only:
     * Business Discovery answers for professional accounts and does not exist
     * on Instagram Login. One read per person, kept (a person without a
     * professional account too) for a week, so a busy post costs one call per
     * author. Instagram refusing the person (not professional, not found) is
     * kept as no picture; a failed request only leaves it out and is asked again.
     *
     * @see https://developers.facebook.com/docs/instagram-platform/instagram-api-with-facebook-login/business-discovery
     */
    private function authorAvatar(SocialAccount $account, mixed $username): ?string
    {
        if ($account->platform !== Platform::InstagramFacebook || blank($username)) {
            return null;
        }

        $key = "inbox:instagram-avatar:{$account->id}:{$username}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return data_get($cached, 'url');
        }

        try {
            $body = $this->graphGet($account, "{$account->platform->instagramGraphBaseUrl()}/{$account->instagramAccountId()}", [
                'fields' => "business_discovery.username({$username}){profile_picture_url}",
            ]);
        } catch (InstagramPublishException) {
            $body = [];
        } catch (Throwable) {
            return null;
        }

        $url = data_get($body, 'business_discovery.profile_picture_url');
        Cache::put($key, ['url' => $url], now()->addWeek());

        return $url;
    }

    /** @param array<string, mixed> $comment */
    private function isOwn(SocialAccount $account, array $comment): bool
    {
        $accountId = $account->instagramAccountId();

        return filled($accountId)
            ? (string) data_get($comment, 'from.id') === $accountId
            : filled($account->username) && (data_get($comment, 'from.username') ?? data_get($comment, 'username')) === $account->username;
    }
}
