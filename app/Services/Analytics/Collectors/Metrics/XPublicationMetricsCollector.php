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
use Illuminate\Support\Arr;

class XPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, 'tweet.read', 'users.read');
        $fields = ['public_metrics'];

        if ($publication->provider_published_at->greaterThan(CarbonImmutable::now('UTC')->subDays(30))) {
            $fields[] = 'non_public_metrics';
        }

        [$tweet, $poll, $threadPolls] = $this->read($account, $publication, $fields);

        if (! is_array($tweet)) {
            throw AnalyticsCollectionException::malformed('X post response lacks data.');
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
            'poll' => is_array($poll) ? XPoll::result($poll) : null,
            'thread_polls' => filled($threadPolls) ? $threadPolls : null,
        ]) ?: null);
    }

    /**
     * The post's tweet, its poll and its reply polls: a post whose thread has
     * no poll is read alone, otherwise with its reply polls in one lookup.
     *
     * @param  list<string>  $fields
     * @return array{0: mixed, 1: mixed, 2: list<array<string, mixed>>}
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
     * @return array{0: mixed, 1: mixed, 2: list<array<string, mixed>>}
     */
    private function readPost(SocialAccount $account, string $id, array $fields): array
    {
        $response = $this->get($account, config('trypost.platforms.x.api')."/tweets/{$id}", $this->lookupParameters($fields));

        return [$response->json('data'), $response->json('includes.polls.0'), []];
    }

    /**
     * The post and its reply polls in one lookup: the post's tweet and poll,
     * and each reply poll's result with its reply position.
     *
     * @param  array<int, string>  $replyPolls  tweet ids keyed by reply position
     * @param  list<string>  $fields
     * @return array{0: mixed, 1: mixed, 2: list<array<string, mixed>>}
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
            ->map(fn (string $replyId, int $reply): ?array => is_array($found = $pollOf($tweets->get($replyId)))
                ? ['reply' => $reply, ...XPoll::result($found)]
                : null)
            ->filter()
            ->values()
            ->all();

        return [$tweets->get($id), $pollOf($tweets->get($id)), $threadPolls];
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
        $post = $publication->post;
        $replyIds = (array) $post?->thread_reply_ids;

        return collect(ThreadReplies::of($post?->meta))
            ->filter(fn (array $reply, int $index): bool => ThreadReplies::hasPoll($reply) && is_string(data_get($replyIds, $index)))
            ->map(fn (array $reply, int $index): string => (string) data_get($replyIds, $index))
            ->all();
    }
}
