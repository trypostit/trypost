<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Social\BlueskyLexicon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

class BlueskyPublicationMetricsCollector extends AbstractPublicationMetricsCollector
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

        if (blank($post) && $this->deletedOnNetwork($account, $publication)) {
            throw AnalyticsCollectionException::deletedOnNetwork('Bluesky no longer returns this post.');
        }

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Reactions, $post, 'likeCount'),
            $this->count(MetricKey::Comments, $post, 'replyCount'),
            $this->count(MetricKey::Shares, $post, 'repostCount'),
            $this->count(MetricKey::Quotes, $post, 'quoteCount'),
        ])));
    }

    protected function successfulResponse(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        throw AnalyticsCollectionException::fromResponse(
            $response,
            'publication metrics collection',
            missingObject: $response->json('error') === 'RecordNotFound',
        );
    }

    /**
     * The AppView also leaves out posts it hides (a moderation takedown), so the post
     * counts as deleted only when its own repository answers `RecordNotFound` while the
     * repository's posts, the list discovery reads, still read.
     */
    private function deletedOnNetwork(SocialAccount $account, AnalyticsPublication $publication): bool
    {
        $pds = rtrim((string) data_get($account->meta, 'service', config('trypost.platforms.bluesky.default_service')), '/');
        $repository = ['repo' => $account->platform_user_id, 'collection' => BlueskyLexicon::FEED_POST];

        try {
            $this->get($account, "{$pds}/xrpc/".BlueskyLexicon::GET_RECORD, [...$repository, 'rkey' => $publication->remote_id], authenticated: false);

            return false;
        } catch (AnalyticsCollectionException $exception) {
            if (! $exception->missingObject) {
                return false;
            }
        }

        try {
            $this->get($account, "{$pds}/xrpc/".BlueskyLexicon::LIST_RECORDS, [...$repository, 'limit' => 1], authenticated: false);

            return true;
        } catch (AnalyticsCollectionException) {
            return false;
        }
    }
}
