<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\PostNote\CreatePostNote;
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

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Add a note (internal team comment) to a post. Notes are never published to the network. Mention a teammate with @[Name](user:id), ids from list-post-note-mentionables-tool: they get an email. The post\'s other participants (its author, the member who asked for approval and everyone who wrote a note on it) get an email about the new note. On a post pending approval, only the approvers and the member who asked see notes.')]
class CreatePostNoteTool extends Tool
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

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $note = CreatePostNote::execute($post, $request->user(), $validated);

        return Response::structured((new PostNoteResource($note))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The UUID of the post.'),
            'body' => $schema->string()->required()->description('The note text (max 2000 characters). Mention a teammate with @[Name](user:id).'),
        ];
    }
}
