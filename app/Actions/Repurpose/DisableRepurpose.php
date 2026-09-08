<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Support\Repurpose\RepurposeTransition;

class DisableRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        return RepurposeTransition::apply(
            $repurpose,
            [Status::Active, Status::Paused],
            __('repurposes.errors.only_running_disables'),
            fn (Repurpose $locked) => $locked->update([
                'status' => Status::Disabled,
                'activated_at' => null,
                'paused_reason' => null,
                'next_poll_at' => null,
            ]),
        );
    }
}
