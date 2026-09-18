<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Actions\Repurpose\ActivateRepurpose;
use App\Http\Resources\Api\RepurposeResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceRepurpose;
use App\Mcp\Requests\Repurpose\RepurposeIdRequest;
use App\Models\Repurpose;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Activate a repurpose. Requires at least one destination and stamps the watermark, so only videos published from now on are replicated.')]
class ActivateRepurposeTool extends Tool
{
    use AuthorizesMcpTool, ResolvesWorkspaceRepurpose;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageRepurposes', 'Not authorized to manage repurposes.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(RepurposeIdRequest::rules());
        $repurpose = $this->repurposeInWorkspace($workspace, $validated['repurpose_id']);

        if (! $repurpose instanceof Repurpose) {
            return $repurpose;
        }

        try {
            $repurpose = ActivateRepurpose::execute($repurpose);
        } catch (ValidationException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured((new RepurposeResource($repurpose))->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repurpose_id' => $schema->string()->required()->description('The repurpose to activate.'),
        ];
    }
}
