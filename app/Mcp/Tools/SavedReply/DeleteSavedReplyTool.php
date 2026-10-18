<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SavedReply;

use App\Actions\SavedReply\DeleteSavedReply;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Models\WorkspaceSavedReply;
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
#[Description('Delete a saved reply for everyone in the workspace. Needs a member who publishes directly.')]
class DeleteSavedReplyTool extends Tool
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

        $validated = $request->validate(['saved_reply_id' => ['required', 'string', 'uuid']]);

        $savedReply = WorkspaceSavedReply::query()
            ->where('workspace_id', $workspace->id)
            ->find(data_get($validated, 'saved_reply_id'));

        if (! $savedReply) {
            return Response::error('Saved reply not found.');
        }

        DeleteSavedReply::execute($savedReply);

        return Response::structured(['deleted' => true]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'saved_reply_id' => $schema->string()->required()->description('The saved reply ID to delete.'),
        ];
    }
}
