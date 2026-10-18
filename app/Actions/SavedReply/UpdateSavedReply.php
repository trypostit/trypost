<?php

declare(strict_types=1);

namespace App\Actions\SavedReply;

use App\Models\WorkspaceSavedReply;

class UpdateSavedReply
{
    public static function execute(WorkspaceSavedReply $savedReply, array $data): WorkspaceSavedReply
    {
        $savedReply->update([
            'name' => data_get($data, 'name'),
            'content' => data_get($data, 'content'),
        ]);

        return $savedReply;
    }
}
