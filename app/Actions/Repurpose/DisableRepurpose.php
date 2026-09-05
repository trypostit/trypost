<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;

class DisableRepurpose
{
    /**
     * Disabling clears the watermark. Re-activating later stamps a fresh one,
     * so whatever the creator published while it was off stays off.
     */
    public static function execute(Repurpose $repurpose): Repurpose
    {
        $repurpose->update([
            'status' => Status::Disabled,
            'activated_at' => null,
            'next_poll_at' => null,
        ]);

        return $repurpose->fresh();
    }
}
