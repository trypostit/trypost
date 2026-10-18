<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\ResolveInboxMessages;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\ResolveScope;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\InboxConversation;
use App\Models\InboxMessage;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
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
#[Description('Mark inbox messages (comments, reviews, mentions or direct messages) as resolved. scope is message (one message, needs message_id), thread (the message and every open reply under it, needs message_id), conversation (every open message of it, needs conversation_id), all (every open message of the tab) or older (open messages of the tab older than a month); all and older take optional channel_ids and resolve only the given tab: comments (comments and reviews, the default), mentions or messages (direct messages). A tab turned off for a network resolves nothing there. Returns how many were resolved. Needs a member who publishes directly.')]
class ResolveInboxMessagesTool extends Tool
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
            'scope' => ['required', Rule::enum(ResolveScope::class)],
            'message_id' => ['required_if:scope,message,thread', 'nullable', 'string', 'uuid'],
            'conversation_id' => ['required_if:scope,conversation', 'nullable', 'string', 'uuid'],
            'channel_ids' => ['nullable', 'array'],
            'channel_ids.*' => ['string', 'uuid'],
            'tab' => ['sometimes', Rule::enum(InboxTab::class)],
        ]);

        $scope = ResolveScope::from((string) data_get($validated, 'scope'));
        $message = null;
        $conversation = null;
        $channelIds = [];

        if ($scope->needsMessage()) {
            $message = InboxMessage::query()
                ->whereHas('conversation', fn ($conversations) => $conversations->visibleIn($workspace))
                ->find(data_get($validated, 'message_id'));

            if (! $message) {
                return Response::error('Inbox message not found.');
            }

            if ($denied = $this->denyUnlessCan($request, 'resolve', $message, 'Inbox message not found.')) {
                return $denied;
            }
        } else {
            if ($scope === ResolveScope::Conversation) {
                $conversation = InboxConversation::query()->visibleIn($workspace)->find(data_get($validated, 'conversation_id'));

                if (! $conversation) {
                    return Response::error('Inbox conversation not found.');
                }

                if ($denied = $this->denyUnlessCan($request, 'view', $conversation, 'Inbox conversation not found.')) {
                    return $denied;
                }
            } elseif (filled(data_get($validated, 'channel_ids'))) {
                $requested = collect(data_get($validated, 'channel_ids'))->unique()->values();
                $channelIds = $workspace->socialAccounts()->whereIn('platform', Platform::inboxValues())->whereKey($requested->all())->pluck('id')->all();

                if (count($channelIds) !== $requested->count()) {
                    return Response::error('Social account not found.');
                }
            }

            if ($denied = $this->denyUnlessCan($request, 'publishDirectly', $workspace)) {
                return $denied;
            }
        }

        $resolved = ResolveInboxMessages::execute(
            $workspace,
            $request->user(),
            $scope,
            $message,
            $conversation,
            $channelIds,
            InboxTab::tryFrom((string) data_get($validated, 'tab')) ?? InboxTab::DEFAULT,
        );

        return Response::structured(['resolved' => $resolved]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'scope' => $schema->string()->enum(array_column(ResolveScope::cases(), 'value'))->required()->description('What to resolve.'),
            'message_id' => $schema->string()->description('The inbox message ID. Required when scope is message or thread.'),
            'conversation_id' => $schema->string()->description('The conversation ID. Required when scope is conversation.'),
            'channel_ids' => $schema->array()->items($schema->string())->description('With scope all or older, only the open messages of these channels (social account UUIDs).'),
            'tab' => $schema->string()->enum(array_column(InboxTab::cases(), 'value'))->description('comments (comments and reviews, the default), mentions (posts by other accounts that mention a channel) or messages (direct messages, one conversation per person).'),
        ];
    }
}
