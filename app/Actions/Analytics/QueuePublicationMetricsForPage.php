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
use Carbon\CarbonImmutable;

class QueuePublicationMetricsForPage
{
    public function handle(SocialAccount $account, PublicationPage $page): void
    {
        $providerIds = array_map(fn ($item): string => $item->providerPostId, $page->publications);

        if ($providerIds === []) {
            return;
        }

        AnalyticsPublication::query()
            ->available()
            ->where('social_account_id', $account->id)
            ->whereIn('provider_post_id', $providerIds)
            ->each(function (AnalyticsPublication $publication): void {
                $this->queue($publication);
            });
    }

    public function queue(AnalyticsPublication $publication): void
    {
        if (! in_array($publication->platform->value, Platform::analyticsValues(), true)) {
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

        $days = $publication->platform === Platform::X ? 20 : 30;
        $recent = $publication->provider_published_at->greaterThanOrEqualTo($now->subDays($days)->startOfDay());

        if (! $recent && $publication->dailySnapshots()->exists()) {
            return;
        }

        CollectPublicationMetrics::dispatch(
            $publication->id,
            $now->toDateString(),
            ! $recent,
        )->afterCommit();
    }
}
