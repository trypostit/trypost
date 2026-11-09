<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Social\XPoll;
use App\Support\ThreadReplies;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Uri;

class XPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    /** The post being read, so a 404's problem is matched against it. */
    private string $postId = '';

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, 'tweet.read', 'users.read');
        $fields = ['public_metrics'];

        if ($publication->provider_published_at->greaterThan(CarbonImmutable::now('UTC')->subDays(30))) {
            $fields[] = 'non_public_metrics';
        }

        $this->postId = $publication->remote_id;

        [$tweet, $poll, $threadPolls, $errors] = $this->readUnlessDeleted(
            fn (): array => $this->read($account, $publication, $fields),
            fn (AnalyticsCollectionException $exception): bool => $exception->missingObject,
            $publication->platform,
        );

        if (! is_array($tweet)) {
            throw $this->reportsDeletedPost($errors)
                ? AnalyticsCollectionException::deletedOnNetwork('X no longer returns this post.')
                : AnalyticsCollectionException::malformed('X post response lacks data.');
        }

        $public = (array) data_get($tweet, 'public_metrics', []);
        $private = (array) data_get($tweet, 'non_public_metrics', []);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Impressions, $public, 'impression_count') ?? $this->count(MetricKey::Impressions, $private, 'impression_count'),
            $this->count(MetricKey::Reactions, $public, 'like_count'),
            $this->count(MetricKey::Comments, $public, 'reply_count'),
            $this->count(MetricKey::Shares, $public, 'retweet_count'),
            $this->count(MetricKey::Quotes, $public, 'quote_count'),
            $this->count(MetricKey::Bookmarks, $public, 'bookmark_count'),
            $this->count(MetricKey::LinkClicks, $private, 'url_link_clicks'),
        ])), Arr::whereNotNull([
            'poll' => transform($poll, XPoll::result(...)),
            'thread_polls' => filled($threadPolls) ? $threadPolls : null,
        ]) ?: null);
    }

    /**
     * The post's tweet, its poll and its reply polls: a post whose thread has
     * no poll is read alone, otherwise with its reply polls in one lookup.
     *
     * @param  list<string>  $fields
     * @return array{0: mixed, 1: mixed, 2: list<array<string, mixed>>, 3: array<int, mixed>}
     */
    private function read(SocialAccount $account, AnalyticsPublication $publication, array $fields): array
    {
        $replyPolls = $this->replyPollTweetIds($publication);

        if (blank($replyPolls)) {
            return $this->readPost($account, $publication->remote_id, $fields);
        }

        return $this->readThread($account, $publication->remote_id, $replyPolls, $fields);
    }

    /**
     * One post: its tweet and its poll.
     *
     * @param  list<string>  $fields
     * @return array{0: mixed, 1: mixed, 2: list<array<string, mixed>>, 3: array<int, mixed>}
     */
    private function readPost(SocialAccount $account, string $id, array $fields): array
    {
        $response = $this->get($account, config('trypost.platforms.x.api')."/tweets/{$id}", $this->lookupParameters($fields));

        return [$response->json('data'), $response->json('includes.polls.0'), [], (array) $response->json('errors', [])];
    }

    /**
     * The post and its reply polls in one lookup: the post's tweet and poll,
     * and each reply poll's result with its reply position.
     *
     * @param  array<int, string>  $replyPolls  tweet ids keyed by reply position
     * @param  list<string>  $fields
     * @return array{0: mixed, 1: mixed, 2: list<array<string, mixed>>, 3: array<int, mixed>}
     */
    private function readThread(SocialAccount $account, string $id, array $replyPolls, array $fields): array
    {
        $response = $this->get($account, config('trypost.platforms.x.api').'/tweets', [
            ...$this->lookupParameters([...$fields, 'attachments']),
            'ids' => Arr::join([$id, ...$replyPolls], ','),
        ]);
        $tweets = $response->collect('data')->keyBy('id');
        $polls = $response->collect('includes.polls')->keyBy('id');
        $pollOf = fn (mixed $tweet): mixed => $polls->get((string) data_get($tweet, 'attachments.poll_ids.0'));
        $threadPolls = collect($replyPolls)
            ->map(fn (string $replyId, int $reply): ?array => transform(
                $pollOf($tweets->get($replyId)),
                fn (array $poll): array => ['reply' => $reply, ...XPoll::result($poll)],
            ))
            ->filter()
            ->values()
            ->all();

        return [$tweets->get($id), $pollOf($tweets->get($id)), $threadPolls, (array) $response->json('errors', [])];
    }

    /**
     * X answers a deleted post with its documented `resource-not-found` problem, in the
     * `errors` of a 200 or in the body of a 404. A token that lost access gets 401/403
     * or `not-authorized-for-resource` instead, so the problem alone confirms it.
     */
    protected function successfulResponse(Response $response): Response
    {
        if ($response->notFound() && $this->reportsDeletedPost((array) $response->json('errors', []))) {
            throw AnalyticsCollectionException::fromResponse($response, 'publication metrics collection', missingObject: true);
        }

        return parent::successfulResponse($response);
    }

    /**
     * @param  array<int, mixed>  $errors
     */
    private function reportsDeletedPost(array $errors): bool
    {
        return collect($errors)->contains(fn (mixed $error): bool => Uri::of((string) data_get($error, 'type'))->path() === '2/problems/resource-not-found'
            && data_get($error, 'resource_type') === 'tweet'
            && (string) data_get($error, 'resource_id') === $this->postId);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, string>
     */
    private function lookupParameters(array $fields): array
    {
        return [
            'tweet.fields' => Arr::join($fields, ','),
            'expansions' => 'attachments.poll_ids',
            'poll.fields' => XPoll::LOOKUP_FIELDS,
        ];
    }

    /**
     * The tweet ids of the thread replies TryPost published with a poll, keyed by
     * reply position, so their votes come in the same lookup as the post's.
     *
     * @return array<int, string>
     */
    private function replyPollTweetIds(AnalyticsPublication $publication): array
    {
        if (blank($publication->post_id)) {
            return [];
        }

        $post = $publication->post()->first(['id', 'meta', 'thread_reply_ids']);
        $replyIds = (array) $post?->thread_reply_ids;

        return collect(ThreadReplies::of($post?->meta))
            ->filter(fn (array $reply, int $index): bool => ThreadReplies::hasPoll($reply) && is_string(data_get($replyIds, $index)))
            ->map(fn (array $reply, int $index): string => (string) data_get($replyIds, $index))
            ->all();
    }
}
