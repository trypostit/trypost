<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
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

        $replyPolls = $this->replyPollTweetIds($publication);
        $parameters = [
            'tweet.fields' => Arr::join($fields, ','),
            'expansions' => 'attachments.poll_ids',
            'poll.fields' => XPoll::LOOKUP_FIELDS,
        ];
        $api = rtrim((string) config('trypost.platforms.x.api'), '/');

        if ($replyPolls === []) {
            $response = $this->get($account, "{$api}/tweets/{$publication->remote_id}", $parameters);
            $tweet = $response->json('data');
            $poll = $response->json('includes.polls.0');
            $threadPolls = [];
        } else {
            $response = $this->get($account, "{$api}/tweets", [
                ...$parameters,
                'ids' => Arr::join([$publication->remote_id, ...$replyPolls], ','),
                'tweet.fields' => Arr::join([...$fields, 'attachments'], ','),
            ]);
            $tweets = $response->collect('data')->keyBy('id');
            $polls = $response->collect('includes.polls')->keyBy('id');
            $pollOf = fn (mixed $tweet): mixed => $polls->get((string) data_get($tweet, 'attachments.poll_ids.0'));
            $tweet = $tweets->get($publication->remote_id);
            $poll = $pollOf($tweet);
            $threadPolls = collect($replyPolls)
                ->map(fn (string $id, int $reply): ?array => transform($pollOf($tweets->get($id)), fn (array $found): array => ['reply' => $reply, ...XPoll::result($found)]))
                ->filter()
                ->values()
                ->all();
        }

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
            ->filter(fn (array $reply, int $index): bool => filled(data_get($reply, 'poll')) && is_string(data_get($replyIds, $index)))
            ->map(fn (array $reply, int $index): string => (string) data_get($replyIds, $index))
            ->all();
    }
}
