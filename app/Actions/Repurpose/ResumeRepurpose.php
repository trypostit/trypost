<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;

class ResumeRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        $repurpose->update([
            'status' => Status::Active,
            'next_poll_at' => null,
        ]);

        return $repurpose->fresh();
    }
}
