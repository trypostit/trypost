<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\WritePublicationDailySnapshot;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class CollectPublicationMetrics implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public int $timeout = 180;

    /** @param list<string> $publicationIds */
    public function __construct(
        public array $publicationIds,
        public string $observationDate,
        public bool $baseline = false,
        public bool $refreshSameDay = false,
        public int $retryNumber = 0,
    ) {
        $this->onQueue('analytics');
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            new RateLimited('analytics-publications'),
            (new WithoutOverlapping('analytics-metrics:'.md5(implode(',', $this->publicationIds)).":{$this->observationDate}"))
                ->releaseAfter(300)
                ->expireAfter($this->timeout + 30),
        ];
    }

    public function providerRateLimitKey(): string
    {
        $publication = AnalyticsPublication::query()->find($this->publicationIds[0] ?? '');

        return $publication?->platform->network() ?? 'missing';
    }

    public function retryUntil(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->observationDate, 'UTC')->endOfDay();
    }

    public function handle(
        PublicationMetricsCollectorFactory $collectors,
        WritePublicationDailySnapshot $writer,
    ): void {
        $date = CarbonImmutable::parse($this->observationDate, 'UTC');

        foreach ($this->publicationIds as $publicationId) {
            $publication = AnalyticsPublication::query()->available()->find($publicationId);
            $account = $publication ? SocialAccount::query()
                ->connected()
                ->active()
                ->includedInAnalytics()
                ->find($publication->social_account_id) : null;

            if (! $publication || ! $account || ! $this->eligible($publication, $account, $date)) {
                continue;
            }

            $publication->setRelation('socialAccount', $account);

            try {
                $observation = $collectors->for($publication->platform)->collect($publication, $date);
                $writer->handle($publication, $observation);
            } catch (AnalyticsCollectionException $exception) {
                $this->retry($publication, $date, $exception->category, $exception->retryAt);
            } catch (ConnectionException) {
                $this->retry($publication, $date, 'transient', null);
            }
        }
    }

    private function eligible(AnalyticsPublication $publication, SocialAccount $account, CarbonImmutable $date): bool
    {
        if ($account->workspace_id !== $publication->workspace_id
            || $account->platform !== $publication->platform
            || $account->platform_user_id !== $publication->platform_user_id) {
            return false;
        }

        $isStory = $publication->content_type === PublicationContentType::Story
            && in_array($publication->platform, [Platform::Instagram, Platform::InstagramFacebook], true);

        if ($isStory && CarbonImmutable::now('UTC')->greaterThanOrEqualTo($publication->provider_published_at->addDay())) {
            return false;
        }

        if (! $this->baseline && ! $isStory) {
            $ageLimit = $publication->platform === Platform::X ? 20 : 30;

            if ($publication->provider_published_at->lessThan($date->subDays($ageLimit)->startOfDay())) {
                return false;
            }
        }

        return $this->refreshSameDay || ! AnalyticsPublicationDailySnapshot::query()
            ->where('analytics_publication_id', $publication->id)
            ->whereDate('snapshot_date', $this->observationDate)
            ->exists();
    }

    private function retry(
        AnalyticsPublication $publication,
        CarbonImmutable $date,
        string $category,
        ?CarbonImmutable $providerRetryAt,
    ): void {
        if (! in_array($category, ['rate_limited', 'transient', 'delayed'], true) || $this->retryNumber >= 5) {
            return;
        }

        $now = CarbonImmutable::now('UTC');
        $isStory = $publication->content_type === PublicationContentType::Story;
        $next = $isStory ? $now->addMinutes(30) : null;

        if (! $next) {
            foreach ([6, 10, 14, 18, 22] as $hour) {
                $window = $date->setTime($hour, 0);

                if ($window->greaterThan($now)) {
                    $next = $window;

                    break;
                }
            }
        }

        if (! $next) {
            return;
        }

        if ($providerRetryAt && $providerRetryAt->greaterThan($next)) {
            $next = $providerRetryAt;
        }

        if ($next->greaterThan($date->endOfDay())
            || ($isStory && $next->greaterThanOrEqualTo($publication->provider_published_at->addDay()))) {
            return;
        }

        self::dispatch(
            [$publication->id],
            $this->observationDate,
            $this->baseline,
            $this->refreshSameDay,
            $this->retryNumber + 1,
        )->delay($next)->afterCommit();
    }
}
