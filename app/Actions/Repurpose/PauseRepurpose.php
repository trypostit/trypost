<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;

class PauseRepurpose
{
    /**
     * Pausing keeps the watermark, so resuming picks up where polling stopped
     * and nothing published in the meantime is lost.
     */
    public static function execute(Repurpose $repurpose): Repurpose
    {
        $repurpose->update(['status' => Status::Paused]);

        return $repurpose->fresh();
    }
}
