<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\ListConversationThreads;
use App\Actions\Inbox\ShowInboxConversation;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\InboxConversationResource;
use App\Http\Resources\Api\InboxMessageResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\InboxConversation;
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

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Get one inbox conversation with a page of its top-level comments, newest first, each with the replies under it in tree. A direct message conversation has no tree: each message is its own row. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class GetInboxConversationTool extends Tool
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
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $conversationId = data_get($validated, 'conversation_id');

        $conversation = InboxConversation::query()->visibleIn($workspace)->find($conversationId);

        if (! $conversation) {
            return Response::error('Inbox conversation not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'view', $conversation, 'Inbox conversation not found.')) {
            return $denied;
        }

        $threads = ListConversationThreads::execute($conversation)
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            ...(new InboxConversationResource(ShowInboxConversation::execute($conversation)))->resolve(),
            'messages' => InboxMessageResource::collection($threads->items())->resolve(),
            'total' => $threads->total(),
            'per_page' => $threads->perPage(),
            'current_page' => $threads->currentPage(),
            'last_page' => $threads->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()->required()->description('The inbox conversation ID.'),
            'page' => $schema->integer()->description('Page number of the top-level comments.'),
        ];
    }
}
