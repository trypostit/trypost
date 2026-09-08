<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Actions\Repurpose\ListRepurposes;
use App\Http\Resources\Api\RepurposeResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Requests\Repurpose\ListRepurposesRequest;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the repurposes of the current workspace. A repurpose watches one source account for one video format and republishes every new video to its destinations. Videos published through TryPost are never replicated.')]
#[IsReadOnly]
class ListRepurposesTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageRepurposes', 'Not authorized to manage repurposes.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(ListRepurposesRequest::rules());

        $repurposes = ListRepurposes::execute($workspace, (int) data_get($validated, 'page', 1));

        return Response::structured([
            'repurposes' => RepurposeResource::collection($repurposes->items())->resolve(),
            'total' => $repurposes->total(),
            'per_page' => $repurposes->perPage(),
            'current_page' => $repurposes->currentPage(),
            'last_page' => $repurposes->lastPage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
