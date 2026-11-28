<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Actions\Post\BuildPublishingStats;
use App\Actions\SocialAccount\BuildNetworkHealth;
use App\Dto\Analytics\DateRange;
use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;
use App\Enums\Quota\QuotaSource;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Percent;
use App\Support\Quota\QuotaSnapshots;
use App\Support\Social\NetworkFilter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class BuildAdminDashboard
{
    public const int CHART_DAYS = 14;

    /** A network failing at least this share of its posts in 24 hours needs attention. */
    public const float ALARMING_FAILURE_RATE = 10.0;

    /** …and at least this many, so one failure out of two posts is not an alarm. */
    public const int ALARMING_FAILURES = 3;

    /** A post still publishing after this many minutes is stuck: a worker is likely down. */
    public const int STUCK_MINUTES = 30;

    /** The days the channels connected lately cover. */
    public const int NEW_CHANNEL_DAYS = 7;

    /** A daily quota used past this share needs attention. */
    public const float QUOTA_WARNING_PERCENT = 80.0;

    /** X credits under this many dollars need topping up. */
    public const float X_LOW_BALANCE = 10.0;

    /**
     * The admin's first page: what needs attention now (a network
     * failing, posts stuck publishing, a quota running out), what went out in
     * the last 24 hours, the publishing queue, the last two weeks of
     * publishing, and the channels. Each block leads to
     * its own page.
     *
     * @return array<string, mixed>
     */
    public static function execute(string $timezone): array
    {
        $now = CarbonImmutable::now('UTC');
        $networks = collect(BuildNetworkHealth::execute());
        $published = $networks->sum('last_24h.published');
        $failed = $networks->sum('last_24h.failed');
        $stuck = Post::query()
            ->where('publish_status', PublishStatus::Publishing)
            ->where('publication_updated_at', '<', $now->subMinutes(self::STUCK_MINUTES))
            ->count();

        return [
            'attention' => [
                'networks' => $networks
                    ->filter(fn (array $network): bool => self::isAlarming($network))
                    ->map(fn (array $network): array => [
                        'platform' => data_get($network, 'platform'),
                        'label' => data_get($network, 'label'),
                        'network' => Platform::from(data_get($network, 'platform'))->network(),
                        'failed' => data_get($network, 'last_24h.failed'),
                        'failure_rate' => data_get($network, 'last_24h.failure_rate'),
                    ])
                    ->values()
                    ->all(),
                'stuck_publishing' => $stuck,
                'quotas' => self::quotaAlerts(),
            ],
            'last_24h' => [
                'published' => $published,
                'failed' => $failed,
                'failure_rate' => Percent::of($failed, $published + $failed),
            ],
            'queue' => [
                'scheduled' => Post::query()->where('status', PostStatus::Scheduled)->count(),
                'publishing' => Post::query()->where('publish_status', PublishStatus::Publishing)->count(),
                'retrying' => Post::query()->where('publish_status', PublishStatus::Retrying)->count(),
                'pending_review' => Post::query()->where('publish_status', PublishStatus::PendingReview)->count(),
            ],
            'publishing_days' => BuildPublishingStats::days(DateRange::fromDates(
                $now->setTimezone($timezone)->subDays(self::CHART_DAYS - 1)->toDateString(),
                $now->setTimezone($timezone)->toDateString(),
                $timezone,
            )),
            'channels' => [
                'total' => SocialAccount::query()->count(),
                'last_7_days' => SocialAccount::query()->where('created_at', '>=', $now->subDays(self::NEW_CHANNEL_DAYS))->count(),
                'connected' => SocialAccount::query()->where('status', Status::Connected)->count(),
                'to_reconnect' => SocialAccount::query()->whereIn('status', NetworkFilter::RECONNECT_STATUSES)->count(),
            ],
        ];
    }

    /**
     * A network failing past the alarming share of its posts in the last 24
     * hours, with enough failures for the share to mean something.
     *
     * @param  array<string, mixed>  $network
     */
    private static function isAlarming(array $network): bool
    {
        if (data_get($network, 'last_24h.failure_rate') < self::ALARMING_FAILURE_RATE) {
            return false;
        }

        return data_get($network, 'last_24h.failed') >= self::ALARMING_FAILURES;
    }

    /**
     * The quotas running out, from the latest reading the quota sync kept:
     * a YouTube daily quota used past the warning share, X credits under the
     * low balance.
     *
     * @return list<array{source: string, label: string, detail: string}>
     */
    private static function quotaAlerts(): array
    {
        $alerts = collect(data_get(QuotaSnapshots::latest(QuotaSource::YouTube), 'data.quotas', []))
            ->flatMap(fn (array $quota): array => collect(data_get($quota, 'limits', []))
                ->filter(fn (array $limit): bool => Str::contains((string) data_get($limit, 'name'), 'PerDay') && (int) data_get($limit, 'value') > 0)
                ->map(fn (array $limit): float => Percent::of((int) data_get($quota, 'usage'), (int) data_get($limit, 'value')))
                ->filter(fn (float $percent): bool => $percent >= self::QUOTA_WARNING_PERCENT)
                ->map(fn (float $percent): array => [
                    'source' => QuotaSource::YouTube->value,
                    'label' => Str::after((string) data_get($quota, 'metric'), '/'),
                    'detail' => "{$percent}% of today's quota used",
                ])
                ->all());

        $balance = data_get(QuotaSnapshots::latest(QuotaSource::X), 'data.credits.total_balance');

        if (is_numeric($balance) && (float) $balance < self::X_LOW_BALANCE) {
            $formattedBalance = Number::currency((float) $balance, 'USD', 'en');

            $alerts->push([
                'source' => QuotaSource::X->value,
                'label' => 'X credits',
                'detail' => "{$formattedBalance} left",
            ]);
        }

        return $alerts->values()->all();
    }
}
