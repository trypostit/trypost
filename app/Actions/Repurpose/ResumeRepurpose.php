<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use Illuminate\Validation\ValidationException;

class ResumeRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        if ($repurpose->status !== Status::Paused) {
            throw ValidationException::withMessages([
                'status' => __('repurposes.errors.only_paused_resumes'),
            ]);
        }

        $repurpose->update([
            'status' => Status::Active,
            'next_poll_at' => null,
        ]);

        return $repurpose->fresh();
    }
}
