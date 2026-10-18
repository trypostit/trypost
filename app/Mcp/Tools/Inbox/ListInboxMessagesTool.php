<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Inbox;

use App\Actions\Inbox\ListInboxMessages;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\DateRange;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\Sort;
use App\Enums\Inbox\StatusFilter;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\InboxMessageResource;
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
#[Description('List the inbound comments of the current workspace\'s inbox, one row per top-level comment, with its replies. Filter by channel_ids (one or more channels), status (all, unanswered, replied, resolved), sort and range. Pass tab=mentions for posts by other accounts that mention a channel, or tab=messages for the inbound direct messages. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListInboxMessagesTool extends Tool
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
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $filters = InboxFilters::fromArray([...$validated, 'channels' => data_get($validated, 'channel_ids', [])]);

        $messages = ListInboxMessages::execute($workspace, $filters)
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'messages' => InboxMessageResource::collection($messages->items())->resolve(),
            'total' => $messages->total(),
            'per_page' => $messages->perPage(),
            'current_page' => $messages->currentPage(),
            'last_page' => $messages->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'channel_ids' => $schema->array()->items($schema->string())->description('Only comments of these channels (social account UUIDs of the current workspace, on a network with an inbox; any other id is refused). Leave it out for every channel.'),
            'status' => $schema->string()->enum(array_column(StatusFilter::cases(), 'value'))->description('Which comments to list.'),
            'sort' => $schema->string()->enum(array_column(Sort::cases(), 'value'))->description('Sort order.'),
            'range' => $schema->string()->enum(array_column(DateRange::cases(), 'value'))->description('Only comments with activity in this range.'),
            'tab' => $schema->string()->enum(array_column(InboxTab::cases(), 'value'))->description('comments (comments and reviews, the default), mentions (posts by other accounts that mention a channel) or messages (direct messages, one conversation per person).'),
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
