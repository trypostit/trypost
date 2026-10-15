<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\PostNote\ListPostNotes;
use App\Http\Resources\Api\PostNoteResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesPostNotes;
use App\Models\Post;
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
#[Description('List the notes (internal team comments) on a post, newest first. Notes are never published to the network. Each note has its body and its author (id, name, photo_url). Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListPostNotesTool extends Tool
{
    use AuthorizesMcpTool, ResolvesPostNotes;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $post = $this->notesPost($request, $workspace, data_get($validated, 'post_id'));

        if (! $post instanceof Post) {
            return $post;
        }

        $notes = ListPostNotes::execute($post, (int) data_get($validated, 'page', 1));

        return Response::structured([
            'notes' => PostNoteResource::collection($notes->items())->resolve(),
            'total' => $notes->total(),
            'per_page' => $notes->perPage(),
            'current_page' => $notes->currentPage(),
            'last_page' => $notes->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The UUID of the post.'),
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
