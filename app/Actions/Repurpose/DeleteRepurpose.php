<?php

declare(strict_types=1);

namespace App\Actions\Repurpose;

use App\Models\Repurpose;

class DeleteRepurpose
{
    /**
     * Items cascade with the repurpose; the posts it generated are the
     * workspace's content and stay, with their back-reference nulled.
     */
    public static function execute(Repurpose $repurpose): void
    {
        $repurpose->delete();
    }
}
