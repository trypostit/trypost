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
use App\Enums\Inbox\ReviewReplyState;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\GoogleBusinessPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\InboxMessage;
use App\Models\SocialAccount;
use App\Services\Inbox\Concerns\InboxHttpClient;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Social\ConnectionVerifier;
use App\Support\GoogleBusinessResourceName;
use App\Support\Inbox\RedactedError;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.reviews
 */
class GoogleBusinessInboxDriver implements InboxDriver
{
    use InboxHttpClient;

    public const int REPLY_MAX_BYTES = 4096;

    private const int PAGE_SIZE = 50;

    private const int MAX_PAGES = 20;

    private const int PENDING_RECHECKS = 50;

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /** @return list<ConversationKind> */
    public function kinds(): array
    {
        return [ConversationKind::Review];
    }

    public function poll(SocialAccount $account, ConversationKind $kind, ?string $cursor, CarbonImmutable $since): PollResult
    {
        if ($kind !== ConversationKind::Review) {
            return new PollResult([], $cursor);
        }

        $location = GoogleBusinessResourceName::connectedLocation($account->meta)
            ?? throw new PlatformUnavailableException('This Google Business Profile channel has no location.');
        $stopAt = filled($cursor) ? CarbonImmutable::parse($cursor) : $since;

        $reviews = $this->withFreshToken($account, fn (): Collection => $this->reviews($account, $location['id'], $stopAt));
        $reviews = $reviews->concat($this->withFreshToken($account, fn (): Collection => $this->pendingReviews($account, $location['id'], $reviews)));

        $newest = $reviews
            ->map(fn (array $review): ?string => data_get($review, 'updateTime'))
            ->filter()
            ->sortBy(fn (string $at): int => CarbonImmutable::parse($at)->getTimestamp())
            ->last();

        return new PollResult(
            messages: $reviews->unique('name')->flatMap(fn (array $review): array => $this->toMessages($account, $review))->values()->all(),
            cursor: filled($newest) && (blank($cursor) || CarbonImmutable::parse($newest)->gt($stopAt)) ? $newest : $cursor,
        );
    }

    public function reply(SocialAccount $account, InboxMessage $parent, string $text): SentReply
    {
        $review = filled($parent->root_id) ? InboxMessage::query()->findOrFail($parent->root_id) : $parent;
        $name = (string) $review->platform_message_id;

        $reply = $this->withFreshToken($account, fn (): array => $this->json(
            $this->socialHttp()->withToken($account->access_token)->put("{$this->api()}/{$name}/reply", ['comment' => $text]),
        ));

        $updatedAt = data_get($reply, 'updateTime');

        return new SentReply(
            platformMessageId: "{$name}/reply",
            platformUrl: $review->platform_url,
            platformCreatedAt: filled($updatedAt) ? CarbonImmutable::parse((string) $updatedAt) : CarbonImmutable::now(),
            reviewReplyState: ReviewReplyState::fromGoogle(data_get($reply, 'reviewReplyState')) ?? ReviewReplyState::Pending,
        );
    }

    public function replyLength(SocialAccount $account): int
    {
        return self::REPLY_MAX_BYTES;
    }

    public function replyLengthUnit(): ReplyLengthUnit
    {
        return ReplyLengthUnit::Bytes;
    }

    public function prefillsMention(): bool
    {
        return false;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function reviews(SocialAccount $account, string $location, CarbonImmutable $stopAt): Collection
    {
        $reviews = collect();
        $page = null;

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $body = $this->json($this->socialHttp()->withToken($account->access_token)->get("{$this->api()}/{$location}/reviews", array_filter([
                'pageSize' => self::PAGE_SIZE,
                'orderBy' => 'updateTime desc',
                'pageToken' => $page,
            ])));
            $batch = collect((array) data_get($body, 'reviews', []));
            $fresh = $batch->takeWhile(fn (array $review): bool => CarbonImmutable::parse((string) data_get($review, 'updateTime'))->gt($stopAt));
            $reviews = $reviews->concat($fresh);
            $page = data_get($body, 'nextPageToken');

            if ($fresh->count() < $batch->count() || blank($page)) {
                break;
            }
        }

        return $reviews->values();
    }

    /**
     * A moderation decision does not move the review's updateTime, so replies
     * still pending are looked up on the review list, read in full (up to
     * MAX_PAGES), the longest unchecked first. A review missing from it is read
     * on its own; that read answers 404 even for reviews the list still has,
     * so it removes nothing. No single review can fail the poll unless Google
     * is limiting us.
     *
     * @param  Collection<int, array<string, mixed>>  $alreadyRead
     * @return Collection<int, array<string, mixed>>
     */
    private function pendingReviews(SocialAccount $account, string $location, Collection $alreadyRead): Collection
    {
        $read = $alreadyRead->pluck('name')->all();

        $pending = InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->where('direction', MessageDirection::Outbound->value)
            ->where('review_reply_state', ReviewReplyState::Pending->value)
            ->whereNull('removed_at')
            ->orderBy('updated_at')
            ->limit(self::PENDING_RECHECKS)
            ->get(['id', 'platform_message_id'])
            ->reject(fn (InboxMessage $reply): bool => in_array($this->reviewName($reply), $read, true));

        if ($pending->isEmpty()) {
            return collect();
        }

        InboxMessage::query()->whereKey($pending->modelKeys())->toBase()->update(['updated_at' => now()]);

        $listed = $this->reviews($account, $location, CarbonImmutable::createFromTimestamp(0))->keyBy('name');

        return $pending
            ->map(fn (InboxMessage $reply): ?array => $listed->get($this->reviewName($reply)) ?? $this->recheck($account, $reply))
            ->filter()
            ->values();
    }

    /** @return array<string, mixed>|null */
    private function recheck(SocialAccount $account, InboxMessage $reply): ?array
    {
        $name = $this->reviewName($reply);

        $response = $this->socialHttp()->withToken($account->access_token)->get("{$this->api()}/{$name}");

        if ($response->status() === HttpResponse::HTTP_NOT_FOUND) {
            return null;
        }

        try {
            $review = $this->json($response);
        } catch (GoogleBusinessPublishException $exception) {
            if ($exception->isLimit()) {
                throw $exception;
            }

            RedactedError::report($exception);

            return null;
        }

        return $review;
    }

    private function reviewName(InboxMessage $reply): string
    {
        return str((string) $reply->platform_message_id)->beforeLast('/reply')->toString();
    }

    /**
     * @param  array<string, mixed>  $review
     * @return list<IncomingMessage>
     */
    private function toMessages(SocialAccount $account, array $review): array
    {
        $name = (string) data_get($review, 'name');
        $anonymous = (bool) data_get($review, 'reviewer.isAnonymous');
        $reviewer = $anonymous ? null : data_get($review, 'reviewer.displayName');
        $photo = $anonymous ? null : data_get($review, 'reviewer.profilePhotoUrl');
        $url = data_get($review, 'reviewReplyUrl') ?? data_get($account->meta, 'maps_uri');
        $createdAt = CarbonImmutable::parse((string) data_get($review, 'createTime'));

        $subject = new IncomingSubject(
            platformId: $name,
            text: data_get($review, 'comment'),
            media: $this->media((array) data_get($review, 'reviewMediaItems', [])),
            url: $url,
            publishedAt: $createdAt,
            remoteId: null,
            participantName: $reviewer,
            participantAvatarUrl: $photo,
        );

        $messages = [new IncomingMessage(
            kind: ConversationKind::Review,
            subject: $subject,
            platformMessageId: $name,
            platformParentId: null,
            direction: MessageDirection::Inbound,
            authorPlatformId: null,
            authorName: $reviewer,
            authorUsername: null,
            authorAvatarUrl: $photo,
            body: data_get($review, 'comment'),
            media: [],
            platformUrl: $url,
            platformCreatedAt: $createdAt,
            rating: self::STARS[(string) data_get($review, 'starRating')] ?? null,
        )];

        if (filled(data_get($review, 'reviewReply.comment'))) {
            $messages[] = new IncomingMessage(
                kind: ConversationKind::Review,
                subject: $subject,
                platformMessageId: "{$name}/reply",
                platformParentId: $name,
                direction: MessageDirection::Outbound,
                authorPlatformId: $account->platform_user_id,
                authorName: $account->display_name ?: $account->username,
                authorUsername: null,
                authorAvatarUrl: null,
                body: data_get($review, 'reviewReply.comment'),
                media: [],
                platformUrl: $url,
                platformCreatedAt: CarbonImmutable::parse((string) (data_get($review, 'reviewReply.updateTime') ?? data_get($review, 'updateTime'))),
                meta: array_filter(['policy_violation' => data_get($review, 'reviewReply.policyViolation')]),
                reviewReplyState: ReviewReplyState::fromGoogle(data_get($review, 'reviewReply.reviewReplyState')) ?? ReviewReplyState::Approved,
            );
        }

        return $messages;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array{type: string, url: string, thumbnail_url?: string|null}>
     */
    private function media(array $items): array
    {
        return collect($items)
            ->filter(fn (mixed $item): bool => filled(data_get($item, 'thumbnailUrl')))
            ->map(fn (array $item): array => filled(data_get($item, 'videoUrl'))
                ? ['type' => 'video', 'url' => (string) data_get($item, 'videoUrl'), 'thumbnail_url' => (string) data_get($item, 'thumbnailUrl')]
                : ['type' => 'image', 'url' => (string) data_get($item, 'thumbnailUrl')])
            ->values()
            ->all();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     */
    private function withFreshToken(SocialAccount $account, Closure $call): mixed
    {
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        try {
            return $call();
        } catch (TokenExpiredException) {
            app(ConnectionVerifier::class)->verify($account);
            $account->refresh();
        }

        try {
            return $call();
        } catch (TokenExpiredException $exception) {
            throw new PlatformUnavailableException("Google Business Profile refused the reviews request after a live verify: {$exception->getMessage()}");
        }
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        if ($response->failed()) {
            throw GoogleBusinessPublishException::fromApiResponse($response);
        }

        return (array) $response->json();
    }

    private function api(): string
    {
        return (string) config('trypost.platforms.google_business.reviews_api');
    }
}
