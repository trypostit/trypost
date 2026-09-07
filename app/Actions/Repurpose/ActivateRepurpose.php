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

    public static function assertSourceUsable(Repurpose $repurpose): void
    {
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

    public static function assertDestinationsPublishable(Repurpose $repurpose): void
    {
        if ($repurpose->destinations === []) {
            throw ValidationException::withMessages([
                'destinations' => __('repurposes.errors.destinations_required'),
            ]);
        }

        $accounts = SocialAccount::query()
            ->where('workspace_id', $repurpose->workspace_id)
            ->where('is_active', true)
            ->findMany(array_map(
                fn (array $destination): mixed => data_get($destination, 'social_account_id'),
                $repurpose->destinations,
            ))
            ->keyBy('id');

        if ($accounts->isEmpty()) {
            throw ValidationException::withMessages([
                'destinations' => __('repurposes.errors.destination_unavailable'),
            ]);
        }

        foreach ($repurpose->destinations as $destination) {
            $account = $accounts->get(data_get($destination, 'social_account_id'));

            if ($account === null) {
                continue;
            }

            $violation = PostPlatformMetaRules::requiredMetaViolation($account->platform, data_get($destination, 'meta'));

            if ($violation !== null) {
                throw ValidationException::withMessages(['destinations' => $violation[1]]);
            }
        }
    }
}
