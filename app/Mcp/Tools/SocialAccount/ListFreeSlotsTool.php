<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\Post\Queue\ListFreeQueueSlots;
use App\Http\Resources\Api\ChannelFreeSlotsResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List the next free queue slots of a channel as UTC instants, soonest first: at most 30, within the next 90 days. A slot is free when no scheduled post and no queue request pending approval holds it. Pass one as queue_slot to create-post-tool, or use move-post-to-slot-tool for an existing post.')]
class ListFreeSlotsTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(['account_id' => ['required', 'string', 'uuid']]);

        $account = SocialAccount::where('workspace_id', $workspace->id)->find(data_get($validated, 'account_id'));

        if (! $account) {
            return Response::error('Social account not found.');
        }

        return Response::structured((new ChannelFreeSlotsResource(ListFreeQueueSlots::handle($account)))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected social account.'),
        ];
    }
}
