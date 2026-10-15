<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\PostNote\UpdatePostNote;
use App\Http\Resources\Api\PostNoteResource;
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
#[Description('Edit the text of a note on a post. Only the note author can edit it. No email is sent.')]
class UpdatePostNoteTool extends Tool
{
    use AuthorizesMcpTool, ResolvesPostNotes;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $ids = $request->validate([
            'post_id' => ['required', 'uuid'],
            'note_id' => ['required', 'uuid'],
        ]);

        $post = $this->notesPost($request, $workspace, data_get($ids, 'post_id'));

        if (! $post instanceof Post) {
            return $post;
        }

        $note = $this->noteOfPost($post, data_get($ids, 'note_id'));

        if (! $note instanceof PostNote) {
            return $note;
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $note)) {
            return $denied;
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        return Response::structured((new PostNoteResource(UpdatePostNote::execute($post, $note, $validated)))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The UUID of the post.'),
            'note_id' => $schema->string()->required()->description('The UUID of the note, from list-post-notes-tool.'),
            'body' => $schema->string()->required()->description('The new note text (max 2000 characters).'),
        ];
    }
}
