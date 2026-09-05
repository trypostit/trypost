<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Actions\Repurpose\ListRepurposes;
use App\Http\Resources\Api\RepurposeResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('List the repurposes of the current workspace. A repurpose watches one source account for one video format and republishes every new video to its destinations. Videos published through TryPost are never replicated.')]
class ListRepurposesTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageRepurposes', 'Not authorized to manage repurposes.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        return Response::structured([
            'repurposes' => RepurposeResource::collection(ListRepurposes::execute($workspace))->resolve(),
        ]);
    }
}
