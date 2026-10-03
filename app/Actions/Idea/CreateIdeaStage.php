<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Models\IdeaStage;
use App\Models\Workspace;

class CreateIdeaStage
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(Workspace $workspace, array $data): IdeaStage
    {
        $lastPosition = $workspace->ideaStages()->max('position');

        return $workspace->ideaStages()->create([
            'name' => data_get($data, 'name'),
            'position' => $lastPosition === null ? 0 : $lastPosition + 1,
        ]);
    }
}
