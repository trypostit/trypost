<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Signature;

use App\Actions\Signature\ListSignatures;
use App\Http\Resources\Api\SignatureResource;
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
#[Description('List the signatures for the current workspace. Signatures are reusable text blocks (hashtags, links, custom text) that you can append to a post content; a signature is not added automatically to any post. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListSignaturesTool extends Tool
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

        $signatures = ListSignatures::execute($workspace)->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'signatures' => SignatureResource::collection($signatures->items())->resolve(),
            'total' => $signatures->total(),
            'per_page' => $signatures->perPage(),
            'current_page' => $signatures->currentPage(),
            'last_page' => $signatures->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
