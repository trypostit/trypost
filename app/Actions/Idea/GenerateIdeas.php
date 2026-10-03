<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Ai\Agents\IdeaGenerator;
use App\Models\Idea;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateIdeas
{
    /**
     * @return EloquentCollection<int, Idea>
     */
    public static function execute(
        Workspace $workspace,
        User $user,
        ?string $stageId,
        int $count,
        string $business,
        string $audience,
        ?string $notes,
    ): EloquentCollection {
        $response = (new IdeaGenerator($user->locale, $count, $business, $audience, $notes))
            ->prompt("Suggest {$count} content ideas.");

        $suggestions = collect(data_get($response, 'ideas', []))
            ->filter(fn (mixed $suggestion): bool => is_array($suggestion) && filled(trim((string) data_get($suggestion, 'title'))))
            ->take($count);

        return DB::transaction(function () use ($workspace, $user, $stageId, $suggestions): EloquentCollection {
            $position = CreateIdea::nextPosition($workspace, $stageId);

            return new EloquentCollection($suggestions->map(fn (array $suggestion): Idea => tap($workspace->ideas()->create([
                'user_id' => $user->id,
                'idea_stage_id' => $stageId,
                'title' => Str::substr(trim((string) data_get($suggestion, 'title')), 0, 255),
                'body' => Str::substr(trim((string) data_get($suggestion, 'body')), 0, AiPromptRules::PROMPT_MAX_LENGTH) ?: null,
                'media' => [],
                'position' => $position++,
            ]), fn (Idea $idea) => $idea->setRelation('labels', new EloquentCollection)))->values()->all());
        });
    }
}
