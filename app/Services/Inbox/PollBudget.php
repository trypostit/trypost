<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use Carbon\CarbonImmutable;

/**
 * How long one inbox poll may keep reading (trypost.inbox.poll_budget_seconds).
 * Bound scoped, so each queued sync starts its own budget when it first asks.
 * A driver checks it between units of work (a post, a page) and, once it is
 * spent, returns what it read with a cursor that resumes there.
 */
final readonly class PollBudget
{
    public function __construct(private CarbonImmutable $deadline) {}

    public static function start(): self
    {
        return new self(now()->toImmutable()->addSeconds((int) config('trypost.inbox.poll_budget_seconds')));
    }

    public function exhausted(): bool
    {
        return ! now()->lessThan($this->deadline);
    }
}
