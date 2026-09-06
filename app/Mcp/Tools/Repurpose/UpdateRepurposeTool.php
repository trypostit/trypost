<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Actions\Repurpose\UpdateRepurpose;
use App\Http\Resources\Api\RepurposeResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceRepurpose;
use App\Mcp\Requests\Repurpose\UpdateRepurposeRequest;
use App\Models\Repurpose;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a repurpose. Changing the source account or the watched format resets the watermark, so only videos published after the change are replicated.')]
class UpdateRepurposeTool extends Tool
{
    use AuthorizesMcpTool, ResolvesWorkspaceRepurpose;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageRepurposes', 'Not authorized to manage repurposes.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(UpdateRepurposeRequest::rules($workspace->id));
        $repurpose = $this->repurposeInWorkspace($workspace, $validated['repurpose_id']);

        if (! $repurpose instanceof Repurpose) {
            return $repurpose;
        }

        return Response::structured(
            (new RepurposeResource(UpdateRepurpose::execute($repurpose, $validated)))->resolve(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repurpose_id' => $schema->string()->required()->description('The repurpose to update.'),
            'source_social_account_id' => $schema->string()->description('Move the repurpose to another source account.'),
            'source_format' => $schema->string()->description('Which video format to watch: reel, video or story.'),
            'publish_mode' => $schema->string()->description('publish to schedule each replicated video straight away, or draft to leave it in TryPost for review.'),
            'destinations' => $schema->array()->description('Replaces the destination list.'),
        ];
    }
}
