<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use Illuminate\Validation\ValidationException;

class ActivateRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        if (! in_array($repurpose->status, [Status::Draft, Status::Disabled], true)) {
            throw ValidationException::withMessages([
                'status' => __('repurposes.errors.only_idle_activates'),
            ]);
        }

        self::assertPublishable($repurpose);

        $repurpose->update([
            'status' => Status::Active,
            'activated_at' => now(),
            'next_poll_at' => null,
            'last_error' => null,
        ]);

        return $repurpose->fresh();
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

            $violation = PostPlatformMetaRules::missingRequiredMeta($account->platform, data_get($destination, 'meta'));

            if ($violation !== null) {
                throw ValidationException::withMessages(['destinations' => $violation[1]]);
            }
        }
    }
}
