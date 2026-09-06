<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Actions\Repurpose\ActivateRepurpose;
use App\Actions\Repurpose\ResumeRepurpose;
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
use Illuminate\Validation\ValidationException;
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
                $this->resumeRecovered($account);

                return;
            }

            foreach ($this->sourcedBy($account) as $repurpose) {
                $this->pause($repurpose, PauseReason::SourceUnavailable);
            }
        }, $account);
    }

    /**
     * Read from the database, not from the instance. The observer receives
     * whatever model the caller happened to be holding, and a column it never
     * loaded — is_active is not in SocialAccountFactory, so a freshly created
     * account has no such attribute in memory — reads back as null rather than
     * throwing, because strict mode exempts recently-created models. That turns
     * a healthy account into a false negative and silently skips auto-resume.
     */
    private function isUsable(SocialAccount $account): bool
    {
        return SocialAccount::query()
            ->whereKey($account->id)
            ->where('is_active', true)
            ->where('status', AccountStatus::Connected)
            ->exists();
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
     * Only SourceUnavailable can auto-resume. SourceRemoved and NoDestinations
     * describe state no account event restores — a reconnection after a delete
     * is a new row, and a pruned destination is gone from the JSON — so both
     * wait for the user.
     *
     * Eligibility is checked before calling Resume, not discovered from its
     * exception: it throws on both a wrong status and a failed health gate, and
     * driving normal control flow through exceptions inside an observer would
     * fill the log with expected failures on every verification sweep.
     */
    private function resumeRecovered(SocialAccount $account): void
    {
        $candidates = Repurpose::query()
            ->where('source_social_account_id', $account->id)
            ->where('status', Status::Paused)
            ->where('paused_reason', PauseReason::SourceUnavailable)
            ->get();

        foreach ($candidates as $repurpose) {
            try {
                ActivateRepurpose::assertSourceUsable($repurpose);
                ActivateRepurpose::assertDestinationsPublishable($repurpose);
            } catch (ValidationException) {
                continue;
            }

            ResumeRepurpose::execute($repurpose);
        }
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
