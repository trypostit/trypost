<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\PauseReason;
use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Support\Repurpose\RepurposeTransition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
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

            $this->pruneDestination($account);
        }, $account);
    }

    public function accountChanged(SocialAccount $account): void
    {
        $this->guard(function () use ($account): void {
            if ($account->wasChanged('platform')) {
                $this->realignDestinations($account);
            }

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

    /**
     * The id can never resolve again, so it comes out of the stored list. A
     * deactivated account is never pruned: that is recoverable, and pruning
     * would lose the destination for good when it is switched back on.
     */
    private function pruneDestination(SocialAccount $account): void
    {
        foreach ($this->destinedFor($account) as $repurpose) {
            $remaining = array_values(array_filter(
                $repurpose->destinations,
                fn (array $destination): bool => data_get($destination, 'social_account_id') !== $account->id,
            ));

            $repurpose->update(['destinations' => $remaining]);

            if ($remaining === []) {
                $this->pause($repurpose, PauseReason::NoDestinations);
            }
        }
    }

    /**
     * Reconnecting through the other variant of a network moves the row's
     * platform, and the stored content type may not exist there —
     * ContentType::forPlatform() shares nothing between LinkedIn and LinkedIn
     * Page. SocialAccount::realignUnpublishedTargets() already does this repair
     * for pending post targets; the repurpose's JSON was never included.
     */
    private function realignDestinations(SocialAccount $account): void
    {
        $supported = array_map(
            fn (ContentType $contentType): string => $contentType->value,
            ContentType::forPlatform($account->platform),
        );

        foreach ($this->destinedFor($account) as $repurpose) {
            $destinations = array_map(function (array $destination) use ($account, $supported): array {
                if (data_get($destination, 'social_account_id') !== $account->id) {
                    return $destination;
                }

                if (in_array(data_get($destination, 'content_type'), $supported, true)) {
                    return $destination;
                }

                $destination['content_type'] = ContentType::defaultFor($account->platform)->value;

                return $destination;
            }, $repurpose->destinations);

            $repurpose->update(['destinations' => array_values($destinations)]);
        }
    }

    /**
     * Filtered in PHP on purpose: `destinations` holds objects, and
     * partial-object containment needs a different candidate shape on
     * PostgreSQL (`@>` wants it wrapped in an array) than on MySQL. The row
     * count is bounded by connected accounts times source formats.
     *
     * @return SupportCollection<int, Repurpose>
     */
    private function destinedFor(SocialAccount $account): SupportCollection
    {
        return Repurpose::query()
            ->where('workspace_id', $account->workspace_id)
            ->get()
            ->filter(fn (Repurpose $repurpose): bool => collect($repurpose->destinations)
                ->contains(fn (array $destination): bool => data_get($destination, 'social_account_id') === $account->id))
            ->values();
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
