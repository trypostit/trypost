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
            function (Repurpose $locked): void {
                ActivateRepurpose::assertSourceUsable($locked);
                ActivateRepurpose::assertDestinationsPublishable($locked);

                $locked->update([
                    'status' => Status::Active,
                    // A pause the system imposed starts fresh: replaying the
                    // outage would flood the destinations with a backlog nobody
                    // asked for. A pause the user chose keeps its place.
                    'activated_at' => $locked->paused_reason !== null ? now() : ($locked->activated_at ?? now()),
                    'paused_reason' => null,
                    'next_poll_at' => null,
                ]);
            },
        );
    }
}
