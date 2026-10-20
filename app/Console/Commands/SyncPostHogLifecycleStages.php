<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Account\ResolveLifecycleStage;
use App\Jobs\PostHog\TrackLifecycleChange;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncPostHogLifecycleStages extends Command
{
    protected $signature = 'posthog:sync-lifecycle-stages';

    protected $description = 'Resolve every account lifecycle stage and activation, and send the changes to PostHog';

    public function handle(): int
    {
        if (! PostHogService::isEnabled()) {
            return self::SUCCESS;
        }

        Account::query()
            ->with('subscriptions')
            ->withLifecycleActivity()
            ->lazyById(200)
            ->each(fn (Account $account) => $this->sync($account));

        return self::SUCCESS;
    }

    private function sync(Account $account): void
    {
        $previousStage = $account->lifecycle_stage;
        $stage = ResolveLifecycleStage::execute($account, $account->last_published_at, $account->has_social_account);
        $activatedNow = blank($account->activated_at) && filled($account->first_published_at);

        if ($stage === $previousStage && ! $activatedNow) {
            return;
        }

        if ($stage !== $previousStage) {
            $account->lifecycle_stage = $stage;
            $account->lifecycle_stage_changed_at = now();
        }

        if ($activatedNow) {
            $account->activated_at = $account->first_published_at;
        }

        rescue(function () use ($account, $previousStage, $stage, $activatedNow): void {
            DB::transaction(function () use ($account, $previousStage, $stage, $activatedNow): void {
                Account::withoutTimestamps(fn (): bool => $account->saveQuietly());

                TrackLifecycleChange::dispatch((string) $account->id, $previousStage, $stage, $account->lifecycle_stage_changed_at, $account->activated_at, $activatedNow);
            });
        });
    }
}
