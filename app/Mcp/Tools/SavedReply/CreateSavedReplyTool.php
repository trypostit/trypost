<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SavedReply;

use App\Actions\SavedReply\CreateSavedReply;
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

#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Create a saved reply for the inbox: a name (up to 100 characters) and the reply text (up to 1000 characters). The text may use {name}, {first_name} and {username}, which the inbox fills in with the person being answered when the reply is inserted. Needs a member who publishes directly.')]
class CreateSavedReplyTool extends Tool
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
            'name' => ['required', 'string', 'max:100'],
            'content' => ['required', 'string', 'max:1000'],
        ]);

        $savedReply = CreateSavedReply::execute($workspace, $validated);

        return Response::structured((new SavedReplyResource($savedReply))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('The saved reply name, up to 100 characters.'),
            'content' => $schema->string()->required()->description('The reply text, up to 1000 characters. May use {name}, {first_name} and {username}.'),
        ];
    }
}
