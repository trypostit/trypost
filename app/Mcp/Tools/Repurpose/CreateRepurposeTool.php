<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Actions\Repurpose\CreateRepurpose;
use App\Http\Resources\Api\RepurposeResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Requests\Repurpose\CreateRepurposeRequest;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a repurpose. It starts as a draft and only replicates videos published after it is activated. Source must be an Instagram or Facebook account, the only networks that allow downloading the video. Each destination picks the format it publishes as, so a Story can land as a Reel.')]
class CreateRepurposeTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageRepurposes', 'Not authorized to manage repurposes.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(CreateRepurposeRequest::rules($workspace->id, $request->all()));

        try {
            $repurpose = CreateRepurpose::execute($workspace, $request->user(), $validated);
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
            'source_social_account_id' => $schema->string()->required()->description('Instagram or Facebook account to watch.'),
            'source_format' => $schema->string()->description('Which video format to watch: reel, video or story. Defaults to reel.'),
            'publish_mode' => $schema->string()->description('publish to schedule each replicated video straight away, or draft to leave it in TryPost for review. Defaults to publish.'),
            'destinations' => $schema->array()->description('Accounts to republish to, each with a content_type that accepts video and optional per-platform meta.'),
        ];
    }
}
