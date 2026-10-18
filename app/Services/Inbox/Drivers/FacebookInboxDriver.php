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
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\MetaGraphRequests;
use App\Services\Inbox\Concerns\ScansRecentPublications;
use App\Services\Inbox\Concerns\SendsMetaDirectMessages;
use App\Services\Inbox\Contracts\DirectMessageDriver;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\Contracts\ReceivesInboxWebhooks;
use App\Services\Inbox\InboxDrivers;
use App\Services\Inbox\Meta\MetaDirectMessages;
use App\Services\Inbox\PollBudget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Comments on the Page's posts, posts and comments that mention the Page,
 * and its Messenger conversations.
 *
 * @see https://developers.facebook.com/docs/graph-api/webhooks/reference/page/
 * @see https://developers.facebook.com/docs/graph-api/reference/page/tagged/
 * @see https://developers.facebook.com/docs/graph-api/reference/object/comments/
 * @see https://developers.facebook.com/docs/graph-api/reference/page/subscribed_apps/
 */
class FacebookInboxDriver implements DirectMessageDriver, InboxDriver, ReceivesInboxWebhooks
{
    use MetaGraphRequests, ScansRecentPublications, SendsMetaDirectMessages;

    private const string COMMENT_FIELDS = 'id,message,from{id,name,picture.type(large)},created_time,parent{id},permalink_url,is_hidden';

    private const int PAGE_SIZE = 50;

    private const int MAX_COMMENT_PAGES = 10;

    private const string POST_FIELDS = 'id,message,from{id,name,picture.type(large)},created_time,permalink_url,full_picture';

    private const int MAX_TAGGED_PAGES = 10;

    private const array MESSAGE_VERBS = ['add', 'edit', 'edited'];

    private const array REMOVAL_VERBS = ['remove', 'delete'];

    /**
     * The Page hid or showed a comment. The change's `from` is the Page, not
     * the comment's author, so it only moves the hidden state.
     */
    private const array VISIBILITY_VERBS = ['hide' => true, 'unhide' => false];

    private const array MENTION_REMOVAL_VERBS = ['remove', 'delete', 'hide'];

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
            return $this->pollMentions($account, $cursor, $since);
        }

        if ($kind !== ConversationKind::Comment) {
            return new PollResult([], $cursor);
        }

        $graph = config('trypost.platforms.facebook.graph_api');

        return $this->scanPublications($account, $cursor, $since, function (DiscoveredPublication $publication) use ($account, $graph): array {
            $subject = $this->subjectFrom($publication);
            $messages = [];
            $after = null;

            for ($page = 0; $page < self::MAX_COMMENT_PAGES; $page++) {
                $body = $this->graphGet($account, "{$graph}/{$publication->providerPostId}/comments", [
                    'filter' => 'stream',
                    'order' => 'reverse_chronological',
                    'fields' => self::COMMENT_FIELDS,
                    'limit' => self::PAGE_SIZE,
                    'after' => $after,
                ]);

                foreach ((array) data_get($body, 'data', []) as $comment) {
                    $messages[] = $this->toMessage(
                        $account,
                        $subject,
                        $comment,
                        data_get($comment, 'parent.id'),
                        data_get($comment, 'permalink_url'),
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
     * One `feed` change of a Page webhook delivery. A top-level comment's
     * parent is the post itself, so only a parent that is another comment is kept.
     *
     * @param  array<string, mixed>  $value
     */
    public function webhookEvents(SocialAccount $account, array $value): InboxWebhookEvents
    {
        $verb = (string) data_get($value, 'verb');
        $commentId = (string) data_get($value, 'comment_id');

        if (data_get($value, 'item') !== 'comment' || blank($commentId)) {
            return new InboxWebhookEvents;
        }

        if (in_array($verb, self::REMOVAL_VERBS, true)) {
            return new InboxWebhookEvents(removedIds: [$commentId]);
        }

        if (array_key_exists($verb, self::VISIBILITY_VERBS)) {
            return data_get(self::VISIBILITY_VERBS, $verb)
                ? new InboxWebhookEvents(hiddenIds: [$commentId])
                : new InboxWebhookEvents(shownIds: [$commentId]);
        }

        if (! in_array($verb, self::MESSAGE_VERBS, true)) {
            return new InboxWebhookEvents;
        }

        $postId = (string) data_get($value, 'post_id');
        $parentId = data_get($value, 'parent_id');
        $picture = $this->webhookAuthorPicture($account, $commentId, (string) data_get($value, 'from.id'));

        return new InboxWebhookEvents([$this->toMessage(
            $account,
            $this->webhookSubject($postId),
            [...$value, 'id' => $commentId, 'from' => [...(array) data_get($value, 'from', []), 'picture' => ['data' => ['url' => $picture]]]],
            filled($parentId) && $parentId !== $postId ? (string) $parentId : null,
            null,
        )]);
    }

    /**
     * One `mention` change of a Page webhook delivery: a post or a comment
     * that mentions the Page. The payload carries no author (`from` is
     * Workplace only) and, for a post on a person's profile, a post id
     * scoped to the app that the Page cannot read. That post is found on the
     * Page's tagged posts by its own id (the part after the profile), so the
     * mention is stored under the same id the tagged poll uses. A comment is
     * read by its own id; its post is only named when it cannot be read. A
     * mention on the Page's own post is a comment, delivered by `feed`.
     *
     * @see https://developers.facebook.com/docs/graph-api/webhooks/reference/page/#mention
     *
     * @param  array<string, mixed>  $value
     */
    public function mentionWebhookEvents(SocialAccount $account, array $value): InboxWebhookEvents
    {
        $verb = (string) data_get($value, 'verb');
        $postId = (string) data_get($value, 'post_id');
        $commentId = (string) data_get($value, 'comment_id');
        $mentionId = $commentId !== '' ? $commentId : $postId;

        if ($postId === ''
            || $this->isOwnPost($account, $postId)
            || ! in_array(ConversationKind::Mention, app(InboxDrivers::class)->kindsFor($account), true)) {
            return new InboxWebhookEvents;
        }

        if (in_array($verb, self::MENTION_REMOVAL_VERBS, true)) {
            return new InboxWebhookEvents(removedIds: $this->storedMentionIds($account, $mentionId));
        }

        if (! in_array($verb, self::MESSAGE_VERBS, true)) {
            return new InboxWebhookEvents;
        }

        $post = $this->mentioningPost($account, $postId);

        if ($commentId === '') {
            return $post === null
                ? new InboxWebhookEvents
                : new InboxWebhookEvents([$this->toPostMention($this->mentionSubject((string) data_get($post, 'id'), $post), $post)]);
        }

        try {
            $comment = $this->graphGet($account, config('trypost.platforms.facebook.graph_api')."/{$commentId}", ['fields' => self::COMMENT_FIELDS]);
        } catch (FacebookPublishException) {
            return new InboxWebhookEvents;
        }

        $postId = (string) data_get($post, 'id', $postId);
        $subject = $this->mentionSubject($postId, $post ?? ['permalink_url' => data_get($comment, 'permalink_url')]);

        return new InboxWebhookEvents([$this->toCommentMention($account, $subject, $comment, $postId)]);
    }

    /**
     * The post a mention is on: read by its id, else found on the Page's
     * newest tagged posts by the post's own id. Null when neither finds it;
     * the tagged poll still lists a tagged post later.
     *
     * @return array<string, mixed>|null
     */
    private function mentioningPost(SocialAccount $account, string $postId): ?array
    {
        try {
            return $this->graphGet($account, config('trypost.platforms.facebook.graph_api')."/{$postId}", ['fields' => self::POST_FIELDS]);
        } catch (FacebookPublishException) {
            return $this->taggedPost($account, $postId);
        }
    }

    /** @return array<string, mixed>|null */
    private function taggedPost(SocialAccount $account, string $postId): ?array
    {
        try {
            $tagged = $this->graphGet($account, config('trypost.platforms.facebook.graph_api')."/{$account->platform_user_id}/tagged", [
                'fields' => self::POST_FIELDS.',tagged_time',
                'limit' => self::PAGE_SIZE,
            ]);
        } catch (FacebookPublishException) {
            return null;
        }

        return collect((array) data_get($tagged, 'data', []))
            ->first(fn (mixed $post): bool => is_array($post) && self::sameObject((string) data_get($post, 'id'), $postId));
    }

    /**
     * The stored mentions a removal is about. A removal names a post on a
     * person's profile by its app-scoped id, while the mention was stored
     * under the id the tagged posts gave, so both are matched by the post's
     * own id.
     *
     * @return list<string>
     */
    private function storedMentionIds(SocialAccount $account, string $mentionId): array
    {
        $objectId = self::objectId($mentionId);

        return InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->whereHas('conversation', fn (Builder $conversation) => $conversation->where('kind', ConversationKind::Mention->value))
            ->where('platform_message_id', 'like', "%{$objectId}")
            ->pluck('platform_message_id')
            ->filter(fn (string $storedId): bool => self::sameObject($storedId, $mentionId))
            ->push($mentionId)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether two ids name the same Facebook object: a post or comment id is
     * prefixed by the profile or post it belongs to, and that prefix changes
     * with who asks (a person's id is scoped per app and per Page).
     */
    private static function sameObject(string $first, string $second): bool
    {
        return $first !== '' && self::objectId($first) === self::objectId($second);
    }

    private static function objectId(string $id): string
    {
        return Str::afterLast($id, '_');
    }

    /**
     * A reply in a mention is a comment on the mentioning post, or on the
     * top-level comment when the mention is a comment.
     */
    public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
    {
        $root = filled($parent->root_id) ? InboxMessage::query()->findOrFail($parent->root_id) : $parent;
        $target = data_get($root->meta, 'top_level_comment_id') ?: $root->platform_message_id;
        $graph = config('trypost.platforms.facebook.graph_api');
        $body = $this->graphPost($account, "{$graph}/{$target}/comments", ['message' => $text]);

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
        return false;
    }

    public function subscribeWebhooks(SocialAccount $account): bool
    {
        $graph = config('trypost.platforms.facebook.graph_api');
        $body = $this->subscribePageFields($account, $graph, (string) $account->platform_user_id, [
            'feed',
            ...(in_array(ConversationKind::Mention, app(InboxDrivers::class)->kindsFor($account), true) ? ['mention'] : []),
            ...$this->directMessageWebhookFields($account, 'messages', 'message_echoes'),
        ]);

        if (data_get($body, 'success') !== true) {
            throw new FacebookPublishException(userMessage: 'Facebook did not accept the webhook subscription.', category: ErrorCategory::Unknown);
        }

        return true;
    }

    protected function graphException(Response $response): SocialPublishException
    {
        return FacebookPublishException::fromApiResponse($response);
    }

    /**
     * The Page's tagged posts, newest first. The edge takes no time filter,
     * so posts tagged before the window end the read; comments that mention
     * the Page are not listed there and arrive by webhook only.
     */
    private function pollMentions(SocialAccount $account, ?string $cursor, CarbonImmutable $since): PollResult
    {
        $graph = config('trypost.platforms.facebook.graph_api');
        $place = ResumableCursor::from($cursor);
        $stopAt = $place->stopAt($since);
        $messages = [];
        $newest = $place->newest;
        $after = $place->after;

        for ($page = 0; $page < self::MAX_TAGGED_PAGES; $page++) {
            $body = $this->graphGet($account, "{$graph}/{$account->platform_user_id}/tagged", [
                'fields' => self::POST_FIELDS.',tagged_time',
                'limit' => self::PAGE_SIZE,
                'after' => $after,
            ]);
            $listed = (array) data_get($body, 'data', []);
            $fresh = collect($listed)->filter(fn (array $post): bool => self::time(data_get($post, 'tagged_time') ?? data_get($post, 'created_time'))->gt($stopAt));

            foreach ($fresh as $post) {
                $taggedAt = self::time(data_get($post, 'tagged_time') ?? data_get($post, 'created_time'));
                $newest = $newest === null || $taggedAt->gt($newest) ? $taggedAt : $newest;
                $postId = (string) data_get($post, 'id');

                if ($postId !== '' && ! $this->isOwnPost($account, $postId)) {
                    $messages[] = $this->toPostMention($this->mentionSubject($postId, $post), $post);
                }
            }

            $after = $fresh->count() === count($listed) && filled(data_get($body, 'paging.next')) ? data_get($body, 'paging.cursors.after') : null;

            if (blank($after) || app(PollBudget::class)->exhausted()) {
                break;
            }
        }

        return new PollResult($messages, $place->next($after, $newest), partial: filled($after));
    }

    /**
     * Facebook prefixes a post id with the id of the profile or Page it was
     * posted on, so a post on the Page itself carries the Page id.
     */
    private function isOwnPost(SocialAccount $account, string $postId): bool
    {
        return str_starts_with($postId, "{$account->platform_user_id}_")
            || InboxConversation::knows($account, ConversationKind::Comment, $postId);
    }

    /** @param array<string, mixed> $post */
    private function mentionSubject(string $postId, array $post): IncomingSubject
    {
        $picture = data_get($post, 'full_picture');

        return new IncomingSubject(
            platformId: $postId,
            text: data_get($post, 'message'),
            media: filled($picture) ? [['type' => 'image', 'url' => (string) $picture]] : [],
            url: data_get($post, 'permalink_url'),
            publishedAt: filled(data_get($post, 'created_time')) ? self::time(data_get($post, 'created_time')) : null,
            remoteId: null,
            participantName: data_get($post, 'from.name'),
        );
    }

    /** @param array<string, mixed> $post */
    private function toPostMention(IncomingSubject $subject, array $post): IncomingMessage
    {
        return new IncomingMessage(
            kind: ConversationKind::Mention,
            subject: $subject,
            platformMessageId: $subject->platformId,
            platformParentId: null,
            direction: MessageDirection::Inbound,
            authorPlatformId: transform(data_get($post, 'from.id'), fn (mixed $id): string => (string) $id),
            authorName: data_get($post, 'from.name'),
            authorUsername: null,
            authorAvatarUrl: self::avatar($post),
            body: data_get($post, 'message'),
            media: $subject->media,
            platformUrl: $subject->url,
            platformCreatedAt: self::time(data_get($post, 'tagged_time') ?? data_get($post, 'created_time')),
            meta: ['post_id' => $subject->platformId],
        );
    }

    /** @param array<string, mixed> $comment */
    private function toCommentMention(SocialAccount $account, IncomingSubject $subject, array $comment, string $postId): IncomingMessage
    {
        $parentId = (string) data_get($comment, 'parent.id');
        $topLevelCommentId = $parentId !== '' && ! self::sameObject($parentId, $postId) ? $parentId : null;

        return $this->toMessage($account, $subject, $comment, $topLevelCommentId, data_get($comment, 'permalink_url'), ConversationKind::Mention, array_filter([
            'post_id' => $postId,
            'comment_id' => (string) data_get($comment, 'id'),
            'top_level_comment_id' => $topLevelCommentId,
        ]));
    }

    /**
     * A comment's post is read when it is imported (ImportInboxPost), never
     * per delivery: the subject only names it.
     */
    private function webhookSubject(string $postId): IncomingSubject
    {
        return new IncomingSubject($postId, null, [], null, null, $postId);
    }

    /**
     * @param  array<string, mixed>  $comment
     * @param  array<string, mixed>  $meta
     */
    private function toMessage(SocialAccount $account, IncomingSubject $subject, array $comment, mixed $parentId, ?string $url, ConversationKind $kind = ConversationKind::Comment, array $meta = []): IncomingMessage
    {
        $authorId = data_get($comment, 'from.id');

        return new IncomingMessage(
            kind: $kind,
            subject: $subject,
            platformMessageId: (string) data_get($comment, 'id'),
            platformParentId: filled($parentId) ? (string) $parentId : null,
            direction: (string) $authorId === (string) $account->platform_user_id ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: filled($authorId) ? (string) $authorId : null,
            authorName: data_get($comment, 'from.name'),
            authorUsername: null,
            authorAvatarUrl: self::avatar($comment),
            body: data_get($comment, 'message'),
            media: [],
            platformUrl: $url,
            platformCreatedAt: self::time(data_get($comment, 'created_time')),
            meta: $meta,
            hidden: array_key_exists('is_hidden', $comment) ? (bool) data_get($comment, 'is_hidden') : null,
        );
    }

    /**
     * A webhook comment's author picture: the delivery carries none, so the
     * comment is read once per author a day (`from{picture}`). A failed read
     * only leaves the picture out. The Page's own comments use the channel's
     * picture.
     */
    private function webhookAuthorPicture(SocialAccount $account, string $commentId, string $authorId): ?string
    {
        if ($authorId === '' || $authorId === (string) $account->platform_user_id) {
            return null;
        }

        $key = "inbox:facebook-avatar:{$account->id}:{$authorId}";
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return data_get($cached, 'url');
        }

        $comment = rescue(fn (): array => $this->graphGet($account, config('trypost.platforms.facebook.graph_api')."/{$commentId}", ['fields' => 'from{picture.type(large)}']), null, false);

        if (! is_array($comment)) {
            return null;
        }

        $url = self::avatar($comment);
        Cache::put($key, ['url' => $url], now()->addDay());

        return $url;
    }

    /**
     * The author's picture as the read gave it (`from{picture}`). A webhook
     * carries none, so a comment that arrived by webhook gets it on the next
     * read. A default silhouette is no picture.
     *
     * @param  array<string, mixed>  $item
     */
    private static function avatar(array $item): ?string
    {
        return data_get($item, 'from.picture.data.is_silhouette') === true ? null : data_get($item, 'from.picture.data.url');
    }

    private static function time(mixed $value): CarbonImmutable
    {
        return match (true) {
            is_numeric($value) => CarbonImmutable::createFromTimestamp((int) $value),
            filled($value) => CarbonImmutable::parse((string) $value),
            default => CarbonImmutable::now(),
        };
    }
}
