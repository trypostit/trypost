<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use App\Support\Repurpose\RepurposeTransition;
use Illuminate\Validation\ValidationException;

class ActivateRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        return RepurposeTransition::apply(
            $repurpose,
            [Status::Draft, Status::Disabled],
            __('repurposes.errors.only_idle_activates'),
            function (Repurpose $locked): void {
                self::assertSourceUsable($locked);
                self::assertDestinationsPublishable($locked);

                $locked->update([
                    'status' => Status::Active,
                    'activated_at' => now(),
                    'paused_reason' => null,
                    'next_poll_at' => null,
                    'last_error' => null,
                ]);
            },
        );
    }

    /**
     * The source is all-or-nothing: without a working one there is nothing to
     * watch, so a repurpose may not run at all.
     */
    public static function assertSourceUsable(Repurpose $repurpose): void
    {
        // loadMissing, not a bare relation read: shouldBeStrict() is on outside
        // production, and here the model came out of a locking query.
        $account = $repurpose->loadMissing('sourceAccount')->sourceAccount;

        if ($account === null) {
            throw ValidationException::withMessages([
                'source_social_account_id' => __('repurposes.errors.source_missing'),
            ]);
        }

        if (! $account->is_active || $account->status !== AccountStatus::Connected) {
            throw ValidationException::withMessages([
                'source_social_account_id' => __('repurposes.errors.source_unusable'),
            ]);
        }
    }

    /**
     * At least one, not all. A deactivated destination is the user saying
     * "don't post here", which the job already honours by skipping it —
     * demanding every destination be live would let one paused account block
     * editing and resuming every repurpose that lists it.
     */
    public static function assertDestinationsPublishable(Repurpose $repurpose): void
    {
        if ($repurpose->destinations === []) {
            throw ValidationException::withMessages([
                'destinations' => __('repurposes.errors.destinations_required'),
            ]);
        }

        $usable = 0;

        foreach ($repurpose->destinations as $destination) {
            $account = SocialAccount::query()
                ->where('workspace_id', $repurpose->workspace_id)
                ->where('is_active', true)
                ->find(data_get($destination, 'social_account_id'));

            if ($account === null) {
                continue;
            }

            $violation = PostPlatformMetaRules::requiredMetaViolation($account->platform, data_get($destination, 'meta'));

            if ($violation !== null) {
                throw ValidationException::withMessages(['destinations' => $violation[1]]);
            }

            $usable++;
        }

        if ($usable === 0) {
            throw ValidationException::withMessages([
                'destinations' => __('repurposes.errors.destination_unavailable'),
            ]);
        }
    }
}
