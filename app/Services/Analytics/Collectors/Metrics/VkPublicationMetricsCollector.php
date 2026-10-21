<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Services\Social\Vk\VkApi;
use App\Support\Analytics\VkAnalyticsResponse;
use Carbon\CarbonImmutable;

class VkPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);

        if (filled(data_get($account->meta, 'community_token'))) {
            // wall.getById answers error 27 for a community token, like every
            // other wall read; only a user token can measure posts.
            throw new AnalyticsCollectionException(
                'permission',
                'VK wall reads are unavailable with a community token; reconnect the channel with a user token',
            );
        }

        $ownerId = VkAnalyticsResponse::ownerId($account);

        $data = VkAnalyticsResponse::data($this->get($account, VkApi::endpoint('wall.getById'), [
            'posts' => "{$ownerId}_{$publication->remote_id}",
            'v' => VkApi::version(),
        ]), 'publication metrics collection');

        // v5.199 returns response.items[], older versions response[].
        $post = data_get($data, 'items.0') ?? data_get($data, '0');

        if (! is_array($post)) {
            // wall.getById answers 200 with an empty list for a deleted post.
            throw AnalyticsCollectionException::gone('VK post no longer exists');
        }

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Views, $post, 'views.count'),
            $this->count(MetricKey::Reactions, $post, 'likes.count'),
            $this->count(MetricKey::Comments, $post, 'comments.count'),
            $this->count(MetricKey::Shares, $post, 'reposts.count'),
        ])));
    }
}
