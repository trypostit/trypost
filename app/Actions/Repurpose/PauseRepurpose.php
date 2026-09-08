<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Enums\Repurpose\Status;
use App\Models\Repurpose;
use App\Support\Repurpose\RepurposeTransition;

class PauseRepurpose
{
    public static function execute(Repurpose $repurpose): Repurpose
    {
        return RepurposeTransition::apply(
            $repurpose,
            [Status::Active],
            __('repurposes.errors.only_active_pauses'),
            fn (Repurpose $locked) => $locked->update(['status' => Status::Paused]),
        );
    }
}
