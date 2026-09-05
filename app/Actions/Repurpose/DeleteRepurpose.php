<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Models\Repurpose;

class DeleteRepurpose
{
    public static function execute(Repurpose $repurpose): void
    {
        $repurpose->delete();
    }
}
