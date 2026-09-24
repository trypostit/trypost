<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get all saved metrics and metadata for a workspace analytics publication, including posts imported from a connected social network. Use the publication ID from the analytics report top posts.')]
class GetAnalyticsPublicationTool extends Tool
{
    use AuthorizesMcpTool;

    public function __construct(private readonly ReadPublicationAnalytics $analytics) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view', 'Not authorized to view workspace analytics.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'publication_id' => ['required', 'uuid'],
        ]);

        try {
            return Response::structured($this->analytics->latestForWorkspacePublication($workspace, $validated['publication_id']));
        } catch (ModelNotFoundException) {
            return Response::error('Publication not found.');
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'publication_id' => $schema->string()->required()->description('UUID of a publication in the workspace analytics report.'),
        ];
    }
}
