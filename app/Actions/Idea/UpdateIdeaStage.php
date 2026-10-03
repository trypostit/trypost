<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Models\IdeaStage;

class UpdateIdeaStage
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function execute(IdeaStage $stage, array $data): IdeaStage
    {
        $stage->update(['name' => data_get($data, 'name')]);

        return $stage;
    }
}
