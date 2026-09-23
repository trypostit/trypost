<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Contracts\Analytics\PublicationMetricsCollector;
use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use Carbon\CarbonImmutable;

class XPublicationMetricsCollector extends AbstractPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $fields = ['public_metrics'];

        if ($publication->provider_published_at->greaterThan(CarbonImmutable::now('UTC')->subDays(30))) {
            $fields[] = 'non_public_metrics';
        }

        $response = $this->get($account,
            rtrim((string) config('trypost.platforms.x.api'), '/')."/tweets/{$publication->provider_post_id}",
            ['tweet.fields' => implode(',', $fields)],
        );
        $tweet = $response->json('data');

        if (! is_array($tweet)) {
            throw AnalyticsCollectionException::malformed('X post response lacks data.');
        }

        $public = (array) ($tweet['public_metrics'] ?? []);
        $private = (array) ($tweet['non_public_metrics'] ?? []);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Impressions, $public, 'impression_count') ?? $this->count(MetricKey::Impressions, $private, 'impression_count'),
            $this->count(MetricKey::Reactions, $public, 'like_count'),
            $this->count(MetricKey::Comments, $public, 'reply_count'),
            $this->count(MetricKey::Shares, $public, 'retweet_count'),
            $this->count(MetricKey::Quotes, $public, 'quote_count'),
            $this->count(MetricKey::Bookmarks, $public, 'bookmark_count'),
            $this->count(MetricKey::LinkClicks, $private, 'url_link_clicks'),
        ])));
    }
}
