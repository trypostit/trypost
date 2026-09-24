<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class FinalizeAccountDailySnapshots implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    /** @return list<int> */
    public function backoff(): array
    {
        return [300, 900];
    }

    public function __construct(public ?string $observationDate = null, public int $daysAgo = 0)
    {
        $this->onQueue('analytics');
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Analytics follower snapshot finalization dispatch failed', [
            'observation_date' => $this->observationDate,
            'days_ago' => $this->daysAgo,
            'exception' => $exception,
        ]);
    }

    public function handle(): void
    {
        $date = $this->observationDate
            ?? CarbonImmutable::now('UTC')->subDays($this->daysAgo)->toDateString();

        SocialAccount::query()
            ->connected()
            ->active()
            ->includedInAnalytics()
            ->reorder()
            ->lazyById(200)
            ->each(function (SocialAccount $account) use ($date): void {
                FinalizeAccountDailySnapshot::dispatch($account->id, $date);
            });
    }
}
