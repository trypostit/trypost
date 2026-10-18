<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\RetryInboxReply;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\InboxMessageResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\InboxMessage;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
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
#[IsOpenWorld(true)]
#[Description('Send a failed inbox reply again. Only a reply whose delivery_status is failed can be retried; it goes back to sending and is published on the channel\'s network again. Needs a member who publishes directly.')]
class RetryInboxReplyTool extends Tool
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

        $validated = $request->validate([
            'message_id' => ['required', 'string', 'uuid'],
        ]);

        $message = InboxMessage::query()
            ->with('parent')
            ->whereHas('conversation', fn ($conversations) => $conversations->visibleIn($workspace))
            ->find(data_get($validated, 'message_id'));

        if (! $message) {
            return Response::error('Inbox message not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'reply', $message->parent ?? $message, 'Inbox message not found.')) {
            return $denied;
        }

        try {
            $retried = RetryInboxReply::execute($message);
        } catch (ValidationException $exception) {
            return Response::error((string) collect($exception->errors())->flatten()->first());
        }

        return Response::structured((new InboxMessageResource($retried))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()->required()->description('The ID of the failed reply to send again.'),
        ];
    }
}
