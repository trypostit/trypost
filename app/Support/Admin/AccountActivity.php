<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Actions\Admin\Users\CountAccountActivity;
use App\Dto\Analytics\DateRange;
use App\Enums\Post\PublishStatus;
use Illuminate\Support\Collection;

/**
 * Every account's activity over a range, counted once for People → Insights,
 * whose usage and churn risk tabs both read it: the posts each account sent
 * in the range and in the period before, the posts the networks refused, and
 * its channels.
 */
final readonly class AccountActivity
{
    /**
     * @param  Collection<string, int>  $sent
     * @param  Collection<string, int>  $previousSent
     * @param  Collection<string, int>  $failed
     * @param  Collection<string, array{total: int, broken: int}>  $channels
     */
    public function __construct(
        public DateRange $range,
        public Collection $sent,
        public Collection $previousSent,
        public Collection $failed,
        public Collection $channels,
    ) {}

    public static function during(DateRange $range): self
    {
        $previous = $range->previous();

        return new self(
            $range,
            CountAccountActivity::posts($range->startsAt(), $range->endsAt(), PublishStatus::wentOutCases()),
            CountAccountActivity::posts($previous->startsAt(), $previous->endsAt(), PublishStatus::wentOutCases()),
            CountAccountActivity::posts($range->startsAt(), $range->endsAt(), PublishingCounts::failedStatuses()),
            CountAccountActivity::channels(),
        );
    }

    public function sentBy(string $accountId): int
    {
        return (int) $this->sent->get($accountId, 0);
    }

    public function previousSentBy(string $accountId): int
    {
        return (int) $this->previousSent->get($accountId, 0);
    }

    public function failedFor(string $accountId): int
    {
        return (int) $this->failed->get($accountId, 0);
    }

    /**
     * @return array{total: int, broken: int}|null
     */
    public function channelsOf(string $accountId): ?array
    {
        return $this->channels->get($accountId);
    }
}
