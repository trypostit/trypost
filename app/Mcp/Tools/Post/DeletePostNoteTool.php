<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\PostNote\DeletePostNote;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesPostNotes;
use App\Models\Post;
use App\Models\PostNote;
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
#[Description('Delete a note from a post. Only the note author can delete it. This cannot be undone.')]
class DeletePostNoteTool extends Tool
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
            'note_id' => ['required', 'uuid'],
        ]);

        $post = $this->notesPost($request, $workspace, data_get($validated, 'post_id'));

        if (! $post instanceof Post) {
            return $post;
        }

        $note = $this->noteOfPost($post, data_get($validated, 'note_id'));

        if (! $note instanceof PostNote) {
            return $note;
        }

        if ($denied = $this->denyUnlessCan($request, 'delete', $note)) {
            return $denied;
        }

        DeletePostNote::execute($post, $note);

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The UUID of the post.'),
            'note_id' => $schema->string()->required()->description('The UUID of the note to delete.'),
        ];
    }
}
