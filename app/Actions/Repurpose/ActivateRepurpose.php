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
    /**
     * Activation stamps the watermark: only media published after this instant
     * is replicated, so turning a repurpose on never floods the destinations
     * with a back catalogue. A repurpose resuming from `paused` keeps the
     * watermark it already had.
     */
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

    /**
     * TikTok, Pinterest and Discord each need a piece of meta before anything
     * can reach them. Without this an active repurpose would look healthy and
     * turn every replicated video into a failed post.
     */
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
