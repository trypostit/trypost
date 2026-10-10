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
use Illuminate\Database\Eloquent\Builder;

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
            ->whereIn('remote_id', $providerIds)
            ->when($account->platform === Platform::X, fn (Builder $query): Builder => $query->whereDoesntHave('dailySnapshots'))
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

        $days = SyncCadence::metricsWindowDays($publication->platform);
        $recent = $publication->provider_published_at->greaterThanOrEqualTo($now->subDays($days)->startOfDay());

        if (! $recent && $publication->dailySnapshots()->exists()) {
            return;
        }

        $readAt = $this->firstReadAt($publication, $now);

        CollectPublicationMetrics::dispatch(
            $publication->id,
            $readAt->toDateString(),
            ! $recent,
        )->delay($readAt->greaterThan($now) ? $readAt : null)->afterCommit();
    }

    /**
     * A fresh X post has nothing to measure yet, and X bills the read.
     */
    private function firstReadAt(AnalyticsPublication $publication, CarbonImmutable $now): CarbonImmutable
    {
        if ($publication->platform !== Platform::X) {
            return $now;
        }

        $readAt = $publication->provider_published_at->toImmutable()->addMinutes(SyncCadence::X_FIRST_READ_DELAY_MINUTES);

        return $readAt->greaterThan($now) ? $readAt : $now;
    }
}
