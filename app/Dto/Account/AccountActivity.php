<?php

declare(strict_types=1);

namespace App\Dto\Account;

use App\Dto\Analytics\DateRange;
use Illuminate\Support\Collection;

/**
 * Accounts' activity over a range, as `CountAccountActivity` counts it once
 * for the pages that read it (People → Insights' usage and churn risk tabs, a
 * user's health): the posts each account sent in the range and in the period
 * before, the ones sent through the API or MCP, the posts the networks
 * refused, and its channels.
 */
final readonly class AccountActivity
{
    /**
     * @param  Collection<string, int>  $sent
     * @param  Collection<string, int>  $previousSent
     * @param  Collection<string, int>  $automated
     * @param  Collection<string, int>  $failed
     * @param  Collection<string, array{total: int, broken: int}>  $channels
     */
    public function __construct(
        public DateRange $range,
        public Collection $sent,
        public Collection $previousSent,
        public Collection $automated,
        public Collection $failed,
        public Collection $channels,
    ) {}

    public function sentBy(string $accountId): int
    {
        return (int) $this->sent->get($accountId, 0);
    }

    public function previousSentBy(string $accountId): int
    {
        return (int) $this->previousSent->get($accountId, 0);
    }

    public function automatedBy(string $accountId): int
    {
        return (int) $this->automated->get($accountId, 0);
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
