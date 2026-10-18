<?php

declare(strict_types=1);

namespace App\Actions\SavedReply;

use App\Models\Workspace;
use App\Models\WorkspaceSavedReply;

class CreateSavedReply
{
    public static function execute(Workspace $workspace, array $data): WorkspaceSavedReply
    {
        return $workspace->savedReplies()->create([
            'name' => data_get($data, 'name'),
            'content' => data_get($data, 'content'),
        ]);
    }
}
