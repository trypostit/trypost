<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\Repurpose\PauseReason;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Support\Repurpose\RepurposeTransition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps repurposes honest about the social accounts they depend on.
 *
 * Source and destination are handled asymmetrically on purpose. A repurpose
 * cannot run without a working source, so any source failure stops it. A
 * destination is different: a disconnected one keeps flowing to the publisher,
 * which fails the post visibly and lets the user retry it after reconnecting.
 */
class RepurposeAccountSync
{
    /**
     * Called from the observer's `deleting` hook rather than `deleted`: the
     * source FK is nullOnDelete, so by the time `deleted` fires the link is
     * already gone and the affected repurposes can no longer be found.
     */
    public function accountRemoved(SocialAccount $account): void
    {
        $this->guard(function () use ($account): void {
            foreach ($this->sourcedBy($account) as $repurpose) {
                $this->pause($repurpose, PauseReason::SourceRemoved);
            }
        }, $account);
    }

    public function accountChanged(SocialAccount $account): void
    {
        $this->guard(function () use ($account): void {
            if ($this->isUsable($account)) {
                return;
            }

            foreach ($this->sourcedBy($account) as $repurpose) {
                $this->pause($repurpose, PauseReason::SourceUnavailable);
            }
        }, $account);
    }

    private function isUsable(SocialAccount $account): bool
    {
        return $account->is_active && $account->status === AccountStatus::Connected;
    }

    /**
     * Active only. A repurpose the user paused deliberately must not acquire a
     * system reason — that would make it eligible for auto-resume and turn back
     * on something they turned off.
     *
     * @return Collection<int, Repurpose>
     */
    private function sourcedBy(SocialAccount $account): Collection
    {
        return Repurpose::query()
            ->where('source_social_account_id', $account->id)
            ->where('status', Status::Active)
            ->get();
    }

    private function pause(Repurpose $repurpose, PauseReason $reason): void
    {
        RepurposeTransition::applyIfPossible(
            $repurpose,
            [Status::Active],
            fn (Repurpose $locked) => $locked->update([
                'status' => Status::Paused,
                'paused_reason' => $reason,
            ]),
        );
    }

    /**
     * Nothing here may break the account operation that triggered it. The
     * delete hook runs inside `$account->delete()`, so an exception aborts a
     * disconnect with a 500; and `SocialAccount::persistIdentity()` wraps a
     * reconnect in a transaction, so an exception would roll the reconnect back.
     */
    private function guard(callable $work, SocialAccount $account): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            Log::error('Repurpose account sync failed', [
                'social_account_id' => $account->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
