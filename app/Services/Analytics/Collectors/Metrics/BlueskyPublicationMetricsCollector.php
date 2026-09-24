<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Models\AnalyticsPublication;
use App\Services\Social\BlueskyLexicon;
use Carbon\CarbonImmutable;

class BlueskyPublicationMetricsCollector extends AbstractPublicationMetricsCollector implements PublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        $uri = "at://{$account->platform_user_id}/".BlueskyLexicon::FEED_POST."/{$publication->remote_id}";
        $response = $this->get($account,
            rtrim((string) config('trypost.platforms.bluesky.public_appview'), '/').'/xrpc/'.BlueskyLexicon::GET_POSTS,
            ['uris' => [$uri]],
            authenticated: false,
        );

        $post = (array) $response->json('posts.0', []);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Reactions, $post, 'likeCount'),
            $this->count(MetricKey::Comments, $post, 'replyCount'),
            $this->count(MetricKey::Shares, $post, 'repostCount'),
            $this->count(MetricKey::Quotes, $post, 'quoteCount'),
        ])));
    }
}
