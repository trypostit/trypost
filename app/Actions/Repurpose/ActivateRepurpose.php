<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
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
                self::assertPublishable($locked);

                $locked->update([
                    'status' => Status::Active,
                    'activated_at' => now(),
                    'next_poll_at' => null,
                    'last_error' => null,
                ]);
            },
        );
    }

    public static function assertPublishable(Repurpose $repurpose): void
    {
        if ($repurpose->destinations === []) {
            throw ValidationException::withMessages([
                'destinations' => __('repurposes.errors.destinations_required'),
            ]);
        }

        foreach ($repurpose->destinations as $destination) {
            $account = SocialAccount::query()
                ->where('workspace_id', $repurpose->workspace_id)
                ->where('is_active', true)
                ->find(data_get($destination, 'social_account_id'));

            if ($account === null) {
                throw ValidationException::withMessages([
                    'destinations' => __('repurposes.errors.destination_unavailable'),
                ]);
            }

            $violation = PostPlatformMetaRules::requiredMetaViolation($account->platform, data_get($destination, 'meta'));

            if ($violation !== null) {
                throw ValidationException::withMessages(['destinations' => $violation[1]]);
            }
        }
    }
}
