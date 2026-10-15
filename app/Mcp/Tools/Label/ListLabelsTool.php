<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Label;

use App\Actions\Label\ListLabels;
use App\Http\Resources\Api\LabelResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the labels for the current workspace. Labels are colored tags used to categorize posts. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListLabelsTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $labels = ListLabels::execute($workspace)->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'labels' => LabelResource::collection($labels->items())->resolve(),
            'total' => $labels->total(),
            'per_page' => $labels->perPage(),
            'current_page' => $labels->currentPage(),
            'last_page' => $labels->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
