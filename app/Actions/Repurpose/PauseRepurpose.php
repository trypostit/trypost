<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use Illuminate\Validation\ValidationException;

class PauseRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        if ($repurpose->status !== Status::Active) {
            throw ValidationException::withMessages([
                'status' => __('repurposes.errors.only_active_pauses'),
            ]);
        }

        $repurpose->update(['status' => Status::Paused]);

        return $repurpose->fresh();
    }
}
