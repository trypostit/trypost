<?php

declare(strict_types=1);

namespace App\Actions\SavedReply;

use App\Models\WorkspaceSavedReply;

class DeleteSavedReply
{
    public static function execute(WorkspaceSavedReply $savedReply): void
    {
        $savedReply->delete();
    }
}
