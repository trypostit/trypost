<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use Illuminate\Validation\ValidationException;

class DisableRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        if (! in_array($repurpose->status, [Status::Active, Status::Paused], true)) {
            throw ValidationException::withMessages([
                'status' => __('repurposes.errors.only_running_disables'),
            ]);
        }

        $repurpose->update([
            'status' => Status::Disabled,
            'activated_at' => null,
            'next_poll_at' => null,
        ]);

        return $repurpose->fresh();
    }
}
