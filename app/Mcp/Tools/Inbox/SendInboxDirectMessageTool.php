<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\SendInboxDirectMessage;
use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\InboxMessageResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\InboxConversation;
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
#[Description('Send a direct message in an inbox message conversation (kind direct_message: Instagram, Facebook Messenger, Bluesky or Mastodon private mentions). The message is delivered to the person on the network and cannot be taken back; the returned message has delivery_status sending until it is delivered. Instagram and Facebook only accept a message while the conversation\'s reply_window_ends_at is in the future (can_send_message). Needs a member who publishes directly.')]
class SendInboxDirectMessageTool extends Tool
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
            'conversation_id' => ['required', 'string', 'uuid'],
            'text' => ['required', 'string', 'max:25000'],
        ]);

        $conversation = InboxConversation::query()
            ->visibleIn($workspace)
            ->where('kind', ConversationKind::DirectMessage->value)
            ->find(data_get($validated, 'conversation_id'));

        if (! $conversation) {
            return Response::error('Inbox conversation not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'message', $conversation, 'Inbox conversation not found.')) {
            return $denied;
        }

        try {
            $message = SendInboxDirectMessage::execute($conversation, $request->user(), (string) data_get($validated, 'text'));
        } catch (ValidationException $exception) {
            return Response::error((string) collect($exception->errors())->flatten()->first());
        }

        return Response::structured((new InboxMessageResource($message))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()->required()->description('The ID of a direct message conversation (kind direct_message), from list-inbox-conversations-tool with tab messages.'),
            'text' => $schema->string()->required()->description('The message text, within the channel\'s limit (Instagram: 1000 bytes; Messenger: 2000 characters; Bluesky: 1000 characters). On Mastodon the participant\'s @handle is added when the text does not mention them.'),
        ];
    }
}
