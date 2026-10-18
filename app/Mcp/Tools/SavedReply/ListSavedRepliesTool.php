<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SavedReply;

use App\Actions\SavedReply\ListSavedReplies;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\SavedReplyResource;
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
#[Description('List the saved replies of the current workspace, newest first. A saved reply is a reusable answer for the inbox; {name}, {first_name} and {username} in its content stand for the person being answered and are not replaced here. Optional search matches the name or the content. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page. Needs a member who publishes directly.')]
class ListSavedRepliesTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'publishDirectly');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if (! Platform::inboxModuleEnabled()) {
            return Response::error('The inbox is not enabled.');
        }

        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $savedReplies = ListSavedReplies::execute($workspace, trim((string) data_get($validated, 'search')))
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'saved_replies' => SavedReplyResource::collection($savedReplies->items())->resolve(),
            'total' => $savedReplies->total(),
            'per_page' => $savedReplies->perPage(),
            'current_page' => $savedReplies->currentPage(),
            'last_page' => $savedReplies->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Only saved replies whose name or content contains this text.'),
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
