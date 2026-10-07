<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\Post\Queue\ReorderChannelQueue;
use App\Exceptions\Post\QueueBusyException;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Reorder the queue of a channel. post_ids lists the first queued posts of the channel in the new order; the posts swap among the slots they already hold. The ids must be exactly the first reorderable queued posts of the channel, otherwise the queue changed and the call is refused. Only members who publish directly can reorder.')]
class ReorderQueueTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'publishDirectly');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $account = SocialAccount::where('workspace_id', $workspace->id)->find(data_get($request->validate(['account_id' => ['required', 'string', 'uuid']]), 'account_id'));

        if (! $account) {
            return Response::error('Social account not found.');
        }

        $validated = $request->validate([
            'post_ids' => ['required', 'array', 'max:500'],
            'post_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        try {
            ReorderChannelQueue::handle($account, data_get($validated, 'post_ids'));
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        return Response::structured(['post_ids' => data_get($validated, 'post_ids')]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected social account.'),
            'post_ids' => $schema->array()->items($schema->string())->required()->description('UUIDs of the first queued posts of the channel, in the new order (at most 500).'),
        ];
    }
}
