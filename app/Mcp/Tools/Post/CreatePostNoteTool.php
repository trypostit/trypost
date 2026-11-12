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
#[Description('Add a note (an internal team comment, never published to the network) to a post, as the connected user. To mention a member, write @[Name](user:id) with an id from list-post-note-mentionables-tool; to mention everyone who sees the post, write @[all](everyone). The server writes each mention with the person\'s current name, and a mention of someone who cannot see the post becomes plain text. Emails: each mentioned member gets a mention email; the post\'s other participants (its author, the member who asked for approval and everyone who already wrote a note on it) get a new-note email; nobody else is emailed, and each member\'s notification settings apply. Everyone with the post open sees the note at once. On a post pending approval, only the approvers and the member who asked can see its notes. Returns the new note.')]
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
            'body' => $schema->string()->required()->description('The note text (max 2000 characters). Mention a teammate with @[Name](user:id), or everyone with @[all](everyone).'),
        ];
    }
}
