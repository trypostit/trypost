<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\User;
use App\Support\Analytics\InsightsPdfExports;

/**
 * One export's channel: only the user who asked for the PDF may listen.
 */
class InsightsPdfChannel
{
    public function join(User $user, string $export): bool
    {
        return filled(InsightsPdfExports::findFor($user, $export));
    }
}
