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
            BlueskyLexicon::xrpc(config('trypost.platforms.bluesky.public_appview'), BlueskyLexicon::GET_POSTS),
            ['uris' => [$uri]],
            authenticated: false,
        );

        $post = (array) $response->json('posts.0', []);

        if (blank($post) && $this->confirmsDeletion($account, $publication)) {
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
        if (! $response->successful() && $response->json('error') === 'RecordNotFound') {
            throw AnalyticsCollectionException::fromResponse($response, 'publication metrics collection', missingObject: true);
        }

        return parent::successfulResponse($response);
    }

    /**
     * The AppView also leaves out posts it hides (a moderation takedown), so the post
     * counts as deleted only when its own repository answers `RecordNotFound` while the
     * repository's posts, the list discovery reads, still read.
     */
    private function confirmsDeletion(SocialAccount $account, AnalyticsPublication $publication): bool
    {
        $repository = ['repo' => $account->platform_user_id, 'collection' => BlueskyLexicon::FEED_POST];

        return $this->readsMissing(
            fn (): Response => $this->get($account, BlueskyLexicon::xrpc($account->bluesky_service, BlueskyLexicon::GET_RECORD), [...$repository, 'rkey' => $publication->remote_id], authenticated: false),
            fn (AnalyticsCollectionException $exception): bool => $exception->missingObject,
        ) && $this->stillReads(
            fn (): Response => $this->get($account, BlueskyLexicon::xrpc($account->bluesky_service, BlueskyLexicon::LIST_RECORDS), [...$repository, 'limit' => 1], authenticated: false),
        );
    }
}
