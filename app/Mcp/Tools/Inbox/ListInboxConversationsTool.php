<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\ListInboxConversations;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\DateRange;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\PostsFilter;
use App\Enums\Inbox\Sort;
use App\Enums\Inbox\StatusFilter;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\InboxConversationResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
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

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the inbox conversations (comments on the channels\' posts) of the current workspace, the ones with unanswered messages first by default (sort=newest or sort=oldest by activity). Filter by channel_ids (one or more channels), status (all, unanswered, replied, resolved), sort and range. Pass posts=all to list every published post instead, including those without comments (posts=commented, the default, lists the conversations). Pass tab=mentions for posts by other accounts that mention a channel, or tab=messages for direct messages (one conversation per person, with last_message, reply_window_ends_at and can_send_message; answer with send-inbox-direct-message-tool). Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListInboxConversationsTool extends Tool
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
            'channel_ids' => ['sometimes', 'nullable', 'array'],
            'channel_ids.*' => ['bail', 'string', 'uuid', Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id)->whereIn('platform', Platform::inboxValues())],
            'status' => ['sometimes', Rule::enum(StatusFilter::class)],
            'sort' => ['sometimes', Rule::enum(Sort::class)],
            'range' => ['sometimes', Rule::enum(DateRange::class)],
            'tab' => ['sometimes', Rule::enum(InboxTab::class)],
            'posts' => ['sometimes', Rule::enum(PostsFilter::class)],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $filters = InboxFilters::fromArray([...$validated, 'channels' => data_get($validated, 'channel_ids', [])]);

        $conversations = ListInboxConversations::paginate($workspace, $filters, page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'conversations' => InboxConversationResource::collection($conversations->items())->resolve(),
            'total' => $conversations->total(),
            'per_page' => $conversations->perPage(),
            'current_page' => $conversations->currentPage(),
            'last_page' => $conversations->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'channel_ids' => $schema->array()->items($schema->string())->description('Only conversations of these channels (social account UUIDs of the current workspace, on a network with an inbox; any other id is refused). Leave it out for every channel.'),
            'status' => $schema->string()->enum(array_column(StatusFilter::cases(), 'value'))->description('Which conversations to list.'),
            'sort' => $schema->string()->enum(array_column(Sort::cases(), 'value'))->description('Sort order.'),
            'range' => $schema->string()->enum(array_column(DateRange::cases(), 'value'))->description('Only conversations with activity in this range.'),
            'tab' => $schema->string()->enum(array_column(InboxTab::cases(), 'value'))->description('comments (comments and reviews, the default), mentions (posts by other accounts that mention a channel) or messages (direct messages, one conversation per person).'),
            'posts' => $schema->string()->enum(array_column(PostsFilter::cases(), 'value'))->description('Comments tab only: commented (the default) lists the conversations; all lists every published post of the channels with comments, newest first, including posts nobody has commented on yet (those come back with id null, their post_id and zero counts). Honours channel_ids, range and sort; status does not apply.'),
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
