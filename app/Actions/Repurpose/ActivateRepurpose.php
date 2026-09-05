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
        if ($repurpose->destinations === []) {
            throw ValidationException::withMessages([
                'destinations' => __('repurposes.errors.destinations_required'),
            ]);
        }

        self::assertDestinationsCanPublish($repurpose);

        $repurpose->update([
            'status' => Status::Active,
            'activated_at' => now(),
            'next_poll_at' => null,
            'last_error' => null,
        ]);

        return $repurpose->fresh();
    }

    private static function assertDestinationsCanPublish(Repurpose $repurpose): void
    {
        foreach ($repurpose->destinations as $destination) {
            $account = SocialAccount::find(data_get($destination, 'social_account_id'));

            $violation = PostPlatformMetaRules::missingRequiredMeta(
                $account?->platform,
                data_get($destination, 'meta'),
            );

            if ($violation === null) {
                continue;
            }

            throw ValidationException::withMessages([
                'destinations' => $violation[1],
            ]);
        }
    }
}
