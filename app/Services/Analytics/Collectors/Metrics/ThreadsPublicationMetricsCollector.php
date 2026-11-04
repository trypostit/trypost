<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

class ThreadsPublicationMetricsCollector extends AbstractMetaPublicationMetricsCollector
{
    protected function graphBaseUrl(SocialAccount $account): string
    {
        return rtrim((string) config('trypost.platforms.threads.graph_api'), '/');
    }

    protected function postsEdge(): string
    {
        return 'threads';
    }

    protected function read(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, 'threads_basic', 'threads_manage_insights');
        $response = $this->get($account,
            "{$this->graphBaseUrl($account)}/{$publication->remote_id}/insights",
            ['metric' => 'views,likes,replies,reposts,quotes'],
        );
        $items = $response->json('data');

        if (! is_array($items)) {
            throw AnalyticsCollectionException::malformed('Threads insights response lacks data.');
        }

        $values = $this->insights($items);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Views, $values, 'views'),
            $this->count(MetricKey::Reactions, $values, 'likes'),
            $this->count(MetricKey::Comments, $values, 'replies'),
            $this->count(MetricKey::Shares, $values, 'reposts'),
            $this->count(MetricKey::Quotes, $values, 'quotes'),
        ])));
    }
}
