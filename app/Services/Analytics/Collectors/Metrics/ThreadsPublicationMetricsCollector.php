<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PollVotingStatus;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Social\ThreadsPoll;
use Carbon\CarbonImmutable;

class ThreadsPublicationMetricsCollector extends AbstractMetaPublicationMetricsCollector
{
    protected function postsEdge(): string
    {
        return 'threads';
    }

    protected function read(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        AnalyticsCollectionException::unlessGranted($account, 'threads_basic', 'threads_manage_insights');
        $response = $this->get($account,
            "{$account->platform->graphApiUrl()}/{$publication->remote_id}/insights",
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
        ])), $this->readPoll($account, $publication));
    }

    /**
     * A poll's result, read on each metrics read like X's: the insights edge
     * carries no poll, so a poll is read once more for its `poll_attachment`.
     * Only a poll not yet stored closed makes that read (a closed one's result
     * is final), and one the token cannot read keeps the metrics measured.
     *
     * @return array{poll: array<string, mixed>}|null
     */
    private function readPoll(SocialAccount $account, AnalyticsPublication $publication): ?array
    {
        if (! $this->pollMayChange($publication)) {
            return null;
        }

        $post = $this->optional($account, "{$account->platform->graphApiUrl()}/{$publication->remote_id}", ['fields' => ThreadsPoll::LOOKUP_FIELDS]);

        return $this->pollMetadata(ThreadsPoll::metadataFrom(data_get($post, 'poll_attachment')));
    }

    /**
     * A poll whose stored result can still change: until a read after its end
     * stores it closed (`ThreadsPoll::result()`), so the final tally is read.
     */
    private function pollMayChange(AnalyticsPublication $publication): bool
    {
        if ($publication->content_type !== PublicationContentType::Poll) {
            return false;
        }

        return data_get($publication->provider_metadata, 'poll.voting_status') !== PollVotingStatus::Closed->value;
    }
}
