<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
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

        $repurpose->update([
            'status' => Status::Active,
            'activated_at' => now(),
            'next_poll_at' => null,
            'last_error' => null,
        ]);

        return $repurpose->fresh();
    }
}
