<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\Post\Queue\MoveChannelPostToQueueSlot;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Move a scheduled post of a channel onto one of its free queue slots (a UTC instant from list-free-slots-tool); it becomes a queue post in that slot. A slot that is taken, off the schedule or held by a request pending approval is refused. Only members who publish directly can move posts.')]
class MovePostToSlotTool extends Tool
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
            'post_id' => ['required', 'uuid'],
            'slot_at' => ['required', 'date'],
        ]);

        try {
            MoveChannelPostToQueueSlot::handle(
                $account,
                data_get($validated, 'post_id'),
                CarbonImmutable::parse(data_get($validated, 'slot_at'))->utc(),
                $request->user(),
            );
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        $post = Post::query()->with(['socialAccount', 'labels'])->findOrFail(data_get($validated, 'post_id'));

        return Response::structured((new PostResource($post))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected social account.'),
            'post_id' => $schema->string()->required()->description('UUID of a scheduled post of that channel.'),
            'slot_at' => $schema->string()->required()->description('UTC instant (ISO 8601) of a free queue slot of the channel.'),
        ];
    }
}
