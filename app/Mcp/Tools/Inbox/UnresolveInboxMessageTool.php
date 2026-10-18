<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\UnresolveInboxMessage;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\InboxMessageResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\InboxMessage;
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
#[Description('Reopen a resolved comment in the inbox, with the resolved replies in its thread. Needs a member who publishes directly.')]
class UnresolveInboxMessageTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if (! Platform::inboxModuleEnabled()) {
            return Response::error('The inbox is not enabled.');
        }

        $messageId = data_get($request->validate(['message_id' => ['required', 'string', 'uuid']]), 'message_id');

        $message = InboxMessage::query()
            ->whereHas('conversation', fn ($conversations) => $conversations->visibleIn($workspace))
            ->find($messageId);

        if (! $message) {
            return Response::error('Inbox message not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'resolve', $message, 'Inbox message not found.')) {
            return $denied;
        }

        return Response::structured((new InboxMessageResource(UnresolveInboxMessage::execute($message)))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()->required()->description('The ID of the comment to reopen.'),
        ];
    }
}
