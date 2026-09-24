<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Get the current workspace analytics report for a date range, including summary, follower and post charts, top posts, per-account performance, data bounds, and sync coverage.')]
class GetAnalyticsReportTool extends Tool
{
    use AuthorizesMcpTool;

    public function __construct(private readonly BuildWorkspaceAnalyticsReport $analytics) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view', 'Not authorized to view workspace analytics.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $selected = $request->validate([
            'start' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'end' => ['sometimes', 'required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        return Response::structured($this->analytics->forSelection($workspace, $selected));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'start' => $schema->string()->description('Optional start date in YYYY-MM-DD format.'),
            'end' => $schema->string()->description('Optional end date in YYYY-MM-DD format.'),
        ];
    }
}
