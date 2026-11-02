<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Support\XPoll;
use Carbon\CarbonImmutable;

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

        $response = $this->get($account,
            rtrim((string) config('trypost.platforms.x.api'), '/')."/tweets/{$publication->remote_id}",
            [
                'tweet.fields' => implode(',', $fields),
                'expansions' => 'attachments.poll_ids',
                'poll.fields' => 'options,voting_status,end_datetime,duration_minutes',
            ],
        );
        $tweet = $response->json('data');

        if (! is_array($tweet)) {
            throw AnalyticsCollectionException::malformed('X post response lacks data.');
        }

        $public = (array) data_get($tweet, 'public_metrics', []);
        $private = (array) data_get($tweet, 'non_public_metrics', []);
        $poll = data_get($response->json(), 'includes.polls.0');

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Impressions, $public, 'impression_count') ?? $this->count(MetricKey::Impressions, $private, 'impression_count'),
            $this->count(MetricKey::Reactions, $public, 'like_count'),
            $this->count(MetricKey::Comments, $public, 'reply_count'),
            $this->count(MetricKey::Shares, $public, 'retweet_count'),
            $this->count(MetricKey::Quotes, $public, 'quote_count'),
            $this->count(MetricKey::Bookmarks, $public, 'bookmark_count'),
            $this->count(MetricKey::LinkClicks, $private, 'url_link_clicks'),
        ])), is_array($poll) ? XPoll::result($poll) : null);
    }
}
