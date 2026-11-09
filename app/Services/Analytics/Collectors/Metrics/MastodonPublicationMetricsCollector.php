<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Social\Mastodon\MastodonApi;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;

class MastodonPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);

        $response = $this->readUnlessDeleted(
            fn (): Response => $this->get(
                $account,
                MastodonApi::url($account->mastodon_instance, "statuses/{$publication->remote_id}"),
                authenticated: $account->canReadMastodonStatuses(),
            ),
            fn (AnalyticsCollectionException $exception): bool => $exception->gone && $this->confirmsDeletion($account),
            $publication->platform,
        );

        $status = (array) $response->json();

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Reactions, $status, 'favourites_count'),
            $this->count(MetricKey::Comments, $status, 'replies_count'),
            $this->count(MetricKey::Shares, $status, 'reblogs_count'),
        ])));
    }

    /**
     * Mastodon answers 404 both for a deleted status and for a private one read without
     * its owner's token, so only an owner read counts, and only while the account's
     * statuses, the list discovery reads, still read with that token.
     */
    private function confirmsDeletion(SocialAccount $account): bool
    {
        return $account->canReadMastodonStatuses() && $this->stillReads(
            fn (): Response => $this->get($account, MastodonApi::url($account->mastodon_instance, "accounts/{$account->platform_user_id}/statuses"), ['limit' => 1]),
        );
    }
}
