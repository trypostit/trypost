<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;

class PauseRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        $repurpose->update(['status' => Status::Paused]);

        return $repurpose->fresh();
    }
}
