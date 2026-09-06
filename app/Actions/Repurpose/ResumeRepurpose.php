<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Support\Repurpose\RepurposeTransition;

class ResumeRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        return RepurposeTransition::apply(
            $repurpose,
            [Status::Paused],
            __('repurposes.errors.only_paused_resumes'),
            fn (Repurpose $locked) => $locked->update([
                'status' => Status::Active,
                'activated_at' => $locked->activated_at ?? now(),
                'next_poll_at' => null,
            ]),
        );
    }
}
