<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\PostNote\RemovePostNoteReaction;
use App\Http\Resources\Api\PostNoteResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesPostNotes;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\Workspace;
use App\Rules\SingleEmoji;
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
#[Description('Take back your reaction with that emoji on a note of a post. Returns the note with its reactions.')]
class RemovePostNoteReactionTool extends Tool
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

        $validated = $request->validate([
            'emoji' => ['required', 'string', new SingleEmoji],
        ]);

        return Response::structured((new PostNoteResource(RemovePostNoteReaction::execute($note, $request->user(), data_get($validated, 'emoji'))))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The UUID of the post.'),
            'note_id' => $schema->string()->required()->description('The UUID of the note.'),
            'emoji' => $schema->string()->required()->description('The emoji of your reaction to remove.'),
        ];
    }
}
