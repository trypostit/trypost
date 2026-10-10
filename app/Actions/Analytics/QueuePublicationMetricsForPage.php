<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Dto\Analytics\PublicationPage;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Jobs\Analytics\CollectPublicationMetrics;
use App\Jobs\Analytics\ScheduleInstagramStoryMetrics;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\SyncCadence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class QueuePublicationMetricsForPage
{
    public function handle(SocialAccount $account, PublicationPage $page): void
    {
        $providerIds = Arr::pluck($page->publications, 'providerPostId');

        if ($providerIds === []) {
            return;
        }

        AnalyticsPublication::query()
            ->available()
            ->where('social_account_id', $account->id)
            ->whereIn('remote_id', $providerIds)
            ->whereDoesntHave('dailySnapshots')
            ->each($this->dispatchFirstRead(...));
    }

    /**
     * Queues the first read of a publication that has not been measured yet.
     */
    public function queue(AnalyticsPublication $publication): void
    {
        if (! $publication->dailySnapshots()->exists()) {
            $this->dispatchFirstRead($publication);
        }
    }

    private function dispatchFirstRead(AnalyticsPublication $publication): void
    {
        if (! $publication->platform->isIncludedInAnalytics()) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        $isStory = $publication->content_type === PublicationContentType::Story
            && in_array($publication->platform, [Platform::Instagram, Platform::InstagramFacebook], true);

        if ($isStory) {
            if ($publication->provider_published_at->addDay()->greaterThan($now)) {
                ScheduleInstagramStoryMetrics::dispatch($publication->id)->afterCommit();
            }

            return;
        }

        $baseline = $publication->provider_published_at->lessThan(SyncCadence::metricsWindowStart($publication->platform, $now));
        $readAt = $publication->provider_published_at->toImmutable()
            ->addMinutes(SyncCadence::FIRST_READ_DELAY_MINUTES)
            ->max($now);

        CollectPublicationMetrics::dispatch($publication->id, $readAt->toDateString(), $baseline)
            ->delay($readAt)
            ->afterCommit();
    }
}
