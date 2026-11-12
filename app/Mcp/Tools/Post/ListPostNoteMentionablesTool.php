<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\PostNote\ListPostNoteAudience;
use App\Http\Resources\Api\PostNoteMentionableResource;
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
#[Description('List the members who see a post, you included: the people a note on it may mention. Returns [{id, name, photo_url, timezone}]. Mention someone in a note body as @[Name](user:id), or everyone as @[all](everyone).')]
class ListPostNoteMentionablesTool extends Tool
{
    use AuthorizesMcpTool, ResolvesPostNotes;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $post = $this->notesPost($request, $workspace, data_get($request->validate(['post_id' => ['required', 'uuid']]), 'post_id'));

        if (! $post instanceof Post) {
            return $post;
        }

        return Response::structured([
            'mentionables' => PostNoteMentionableResource::collection(ListPostNoteAudience::execute($post))->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The UUID of the post.'),
        ];
    }
}
