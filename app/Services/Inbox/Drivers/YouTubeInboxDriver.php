<?php

declare(strict_types=1);

namespace App\Services\Inbox\Drivers;

use App\Dto\Inbox\IncomingMessage;
use App\Dto\Inbox\IncomingSubject;
use App\Dto\Inbox\PollResult;
use App\Dto\Inbox\SentReply;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\MessageDirection;
use App\Enums\Inbox\ReplyLengthUnit;
use App\Exceptions\Social\YouTubePublishException;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\InboxHttpClient;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\PollBudget;
use App\Services\Inbox\YouTubeQuota;
use App\Services\Social\ConnectionVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

/**
 * @see https://developers.google.com/youtube/v3/docs/commentThreads/list
 * @see https://developers.google.com/youtube/v3/docs/comments/insert
 */
class YouTubeInboxDriver implements InboxDriver
{
    use InboxHttpClient;

    public const int REPLY_MAX_LENGTH = 10000;

    private const int REPLY_UNITS = 50;

    private const int PAGE_SIZE = 100;

    private const int POLL_PAGES = 3;

    private const int BACKFILL_PAGES = 20;

    private const int MAX_REPLY_PAGES = 10;

    private ?string $lastPageToken = null;

    public function __construct(private readonly YouTubeQuota $quota) {}

    /** @return list<ConversationKind> */
    public function kinds(): array
    {
        return [ConversationKind::Comment];
    }

    public function poll(SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): PollResult
    {
        if ($kind !== ConversationKind::Comment) {
            return new PollResult([], $cursor);
        }

        $this->refreshSession($account);

        $withinShare = $this->quota->withinShare($account);
        $threads = $this->threads($account, $since, match (true) {
            ! $withinShare => 1,
            blank($cursor) => self::BACKFILL_PAGES,
            default => self::POLL_PAGES,
        });
        $known = $this->knownRoots($account, $threads);
        $changed = $threads->filter(fn (array $thread): bool => $this->hasChanged($thread, $known))->values();
        $videos = $this->videos($account, $changed->map(fn (array $thread): string => (string) data_get($thread, 'snippet.videoId'))->filter()->unique()->values());

        $messages = [];

        foreach ($changed as $index => $thread) {
            $messages = [...$messages, ...$this->threadMessages($account, $thread, $videos, $withinShare)];

            if ($index < $changed->count() - 1 && app(PollBudget::class)->exhausted()) {
                return new PollResult($messages, $cursor, partial: true);
            }
        }

        $newest = $threads
            ->map(fn (array $thread): ?string => data_get($thread, 'snippet.topLevelComment.snippet.publishedAt'))
            ->filter()
            ->sortBy(fn (string $at): int => CarbonImmutable::parse($at)->getTimestamp())
            ->last();

        return new PollResult($messages, $newest ?? $cursor ?? now()->toIso8601String());
    }

    public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
    {
        $this->refreshSession($account);

        $root = filled($parent->root_id) ? InboxMessage::query()->findOrFail($parent->root_id) : $parent;
        $videoId = $parent->conversation->platform_conversation_id;

        $this->quota->spendOnReply(self::REPLY_UNITS);

        $response = $this->socialHttp()->withToken($account->access_token)
            ->post(config('trypost.platforms.youtube.data_api').'/comments?part=snippet', [
                'snippet' => ['parentId' => $root->platform_message_id, 'textOriginal' => $text],
            ]);

        $this->guard($response);

        $id = (string) $response->json('id');
        $publishedAt = $response->json('snippet.publishedAt');

        return new SentReply(
            platformMessageId: $id,
            platformUrl: $this->commentUrl($videoId, $id),
            platformCreatedAt: filled($publishedAt) ? CarbonImmutable::parse((string) $publishedAt) : CarbonImmutable::now(),
        );
    }

    public function replyLength(SocialAccount $account): int
    {
        return self::REPLY_MAX_LENGTH;
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
     * @return Collection<int, array<string, mixed>>
     */
    private function threads(SocialAccount $account, CarbonImmutable $since, int $maxPages): Collection
    {
        $threads = collect();
        $page = null;

        for ($i = 0; $i < $maxPages; $i++) {
            $batch = collect((array) data_get($this->get($account, 'commentThreads', [
                'part' => 'snippet,replies',
                'allThreadsRelatedToChannelId' => $account->platform_user_id,
                'order' => 'time',
                'maxResults' => self::PAGE_SIZE,
                'textFormat' => 'plainText',
                'pageToken' => $page,
            ]), 'items', []));
            $publishedAt = fn (array $thread): CarbonImmutable => CarbonImmutable::parse((string) data_get($thread, 'snippet.topLevelComment.snippet.publishedAt'));
            $threads = $threads->concat($batch->filter(fn (array $thread): bool => $publishedAt($thread)->gte($since)));
            $page = $this->lastPageToken;

            if (blank($page) || $batch->isEmpty() || $batch->contains(fn (array $thread): bool => $publishedAt($thread)->lt($since))) {
                break;
            }
        }

        return $threads->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $threads
     * @return array<string, array<string, mixed>>
     */
    private function knownRoots(SocialAccount $account, Collection $threads): array
    {
        $hashes = $threads->map(fn (array $thread): string => InboxConversation::hashOf((string) data_get($thread, 'id')))->all();

        return InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->whereIn('platform_message_hash', $hashes)
            ->get(['platform_message_hash', 'meta'])
            ->mapWithKeys(fn (InboxMessage $message): array => [$message->platform_message_hash => (array) $message->meta])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $thread
     * @param  array<string, array<string, mixed>>  $known
     */
    private function hasChanged(array $thread, array $known): bool
    {
        $stored = $known[InboxConversation::hashOf((string) data_get($thread, 'id'))] ?? null;

        return $stored === null
            || (int) data_get($stored, 'total_reply_count') !== (int) data_get($thread, 'snippet.totalReplyCount')
            || data_get($stored, 'updated_at') !== data_get($thread, 'snippet.topLevelComment.snippet.updatedAt');
    }

    /**
     * @param  array<string, mixed>  $thread
     * @param  array<string, array<string, mixed>>  $videos
     * @param  bool  $readAllReplies  false past the channel's daily share: only the replies the thread carries
     * @return list<IncomingMessage>
     */
    private function threadMessages(SocialAccount $account, array $thread, array $videos, bool $readAllReplies): array
    {
        $rootId = (string) data_get($thread, 'id');
        $videoId = (string) data_get($thread, 'snippet.videoId');
        $subject = $this->subject($videoId, $videos[$videoId] ?? []);
        $replies = collect((array) data_get($thread, 'replies.comments', []));

        $totalReplies = (int) data_get($thread, 'snippet.totalReplyCount');

        if ($readAllReplies && $replies->count() < $totalReplies) {
            $replies = $this->replies($account, $rootId);
        }

        $readCount = min($replies->count(), $totalReplies);

        $root = $this->toMessage($account, (array) data_get($thread, 'snippet.topLevelComment'), $subject, null, [
            'total_reply_count' => $readCount,
            'updated_at' => data_get($thread, 'snippet.topLevelComment.snippet.updatedAt'),
        ]);

        return [
            $root,
            ...$replies->map(fn (array $comment): IncomingMessage => $this->toMessage($account, $comment, $subject, $rootId))->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function replies(SocialAccount $account, string $parentId): Collection
    {
        $replies = collect();
        $page = null;
        $pages = 0;

        do {
            $replies = $replies->concat((array) data_get($this->get($account, 'comments', [
                'part' => 'snippet',
                'parentId' => $parentId,
                'maxResults' => self::PAGE_SIZE,
                'textFormat' => 'plainText',
                'pageToken' => $page,
            ]), 'items', []));
            $page = $this->lastPageToken;
            $pages++;
        } while (filled($page) && $pages < self::MAX_REPLY_PAGES);

        return $replies->values();
    }

    /**
     * @param  Collection<int, string>  $videoIds
     * @return array<string, array<string, mixed>>
     */
    private function videos(SocialAccount $account, Collection $videoIds): array
    {
        return $videoIds->chunk(50)->flatMap(fn (Collection $chunk): array => collect((array) data_get($this->get($account, 'videos', [
            'part' => 'snippet',
            'id' => $chunk->implode(','),
        ]), 'items', []))->keyBy('id')->all())->all();
    }

    /** @param array<string, mixed> $video */
    private function subject(string $videoId, array $video): IncomingSubject
    {
        $thumbnail = data_get($video, 'snippet.thumbnails.medium.url') ?: data_get($video, 'snippet.thumbnails.default.url');
        $publishedAt = data_get($video, 'snippet.publishedAt');

        return new IncomingSubject(
            platformId: $videoId,
            text: data_get($video, 'snippet.title'),
            media: filled($thumbnail) ? [['type' => 'image', 'url' => (string) $thumbnail]] : [],
            url: config('trypost.platforms.youtube.web')."/watch?v={$videoId}",
            publishedAt: filled($publishedAt) ? CarbonImmutable::parse((string) $publishedAt) : null,
            remoteId: $videoId,
        );
    }

    /**
     * @param  array<string, mixed>  $comment
     * @param  array<string, mixed>  $meta
     */
    private function toMessage(SocialAccount $account, array $comment, IncomingSubject $subject, ?string $parentId, array $meta = []): IncomingMessage
    {
        $id = (string) data_get($comment, 'id');
        $authorId = data_get($comment, 'snippet.authorChannelId.value');
        $displayName = (string) data_get($comment, 'snippet.authorDisplayName');

        return new IncomingMessage(
            kind: ConversationKind::Comment,
            subject: $subject,
            platformMessageId: $id,
            platformParentId: $parentId,
            direction: $authorId === $account->platform_user_id ? MessageDirection::Outbound : MessageDirection::Inbound,
            authorPlatformId: $authorId,
            authorName: $displayName ?: null,
            authorUsername: ltrim($displayName, '@') ?: null,
            authorAvatarUrl: data_get($comment, 'snippet.authorProfileImageUrl'),
            body: data_get($comment, 'snippet.textDisplay'),
            media: [],
            platformUrl: $this->commentUrl($subject->platformId, $id),
            platformCreatedAt: CarbonImmutable::parse((string) data_get($comment, 'snippet.publishedAt')),
            meta: $meta,
        );
    }

    private function commentUrl(string $videoId, string $commentId): string
    {
        return config('trypost.platforms.youtube.web')."/watch?v={$videoId}&lc={$commentId}";
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(SocialAccount $account, string $resource, array $query): array
    {
        $this->quota->spendOnPoll(1, $account);

        $response = $this->socialHttp()->withToken($account->access_token)
            ->get(config('trypost.platforms.youtube.data_api')."/{$resource}", array_filter($query, fn (mixed $value): bool => filled($value)));

        $this->guard($response);
        $this->lastPageToken = $response->json('nextPageToken');

        return (array) $response->json();
    }

    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $exception = YouTubePublishException::fromApiResponse($response);

        if ($exception->platformErrorCode === 'quotaExceeded') {
            $this->quota->exhaust();
            $exception->retryAt = $this->quota->resetsAt();
        }

        throw $exception;
    }

    private function refreshSession(SocialAccount $account): void
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }
    }
}
