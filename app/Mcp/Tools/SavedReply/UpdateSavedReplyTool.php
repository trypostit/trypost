<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SavedReply;

use App\Actions\SavedReply\UpdateSavedReply;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\SavedReplyResource;
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
#[Description('Change the name and text of a saved reply. Needs a member who publishes directly.')]
class UpdateSavedReplyTool extends Tool
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

        $savedReply = WorkspaceSavedReply::query()
            ->where('workspace_id', $workspace->id)
            ->find(data_get($request->validate(['saved_reply_id' => ['required', 'string', 'uuid']]), 'saved_reply_id'));

        if (! $savedReply) {
            return Response::error('Saved reply not found.');
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:1000'],
        ]);

        return Response::structured((new SavedReplyResource(UpdateSavedReply::execute($savedReply, $validated)))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'saved_reply_id' => $schema->string()->required()->description('The saved reply ID.'),
            'name' => $schema->string()->required()->description('The new name, up to 100 characters.'),
            'content' => $schema->string()->required()->description('The new reply text, up to 1000 characters.'),
        ];
    }
}
