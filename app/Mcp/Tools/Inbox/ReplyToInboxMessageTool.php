<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\ReplyToInboxMessage;
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
#[Description('Reply to a comment in the inbox. The reply is published on the channel\'s network; the returned message has delivery_status sending until it is delivered. Needs a member who publishes directly.')]
class ReplyToInboxMessageTool extends Tool
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
            'text' => ['required', 'string', 'max:25000'],
        ]);

        $message = InboxMessage::query()
            ->whereHas('conversation', fn ($conversations) => $conversations->visibleIn($workspace))
            ->find(data_get($validated, 'message_id'));

        if (! $message) {
            return Response::error('Inbox message not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'reply', $message, 'Inbox message not found.')) {
            return $denied;
        }

        try {
            $reply = ReplyToInboxMessage::execute($message, $request->user(), (string) data_get($validated, 'text'));
        } catch (ValidationException $exception) {
            return Response::error((string) collect($exception->errors())->flatten()->first());
        }

        return Response::structured((new InboxMessageResource($reply))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()->required()->description('The ID of the comment to reply to.'),
            'text' => $schema->string()->required()->description('The reply text, within the channel\'s character limit.'),
        ];
    }
}
