<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Enums\User\Locale;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreateDefaultIdeaStages
{
    public const KEYS = ['todo', 'in_progress', 'done'];

    public static function execute(Workspace $workspace): void
    {
        $locale = $workspace->owner?->locale ?? Locale::DEFAULT;

        DB::transaction(function () use ($workspace, $locale): void {
            if ($workspace->ideaStages()->exists()) {
                return;
            }

            foreach (self::KEYS as $position => $key) {
                $workspace->ideaStages()->create([
                    'name' => __("create.ideas.default_stages.{$key}", [], $locale->value),
                    'position' => $position,
                ]);
            }
        });
    }
}
