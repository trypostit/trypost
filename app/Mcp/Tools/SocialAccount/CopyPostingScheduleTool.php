<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\CopyPostingSchedule;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\ChannelPostingScheduleResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
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

#[IsReadOnly(false)]
#[IsDestructive(true)]
#[IsOpenWorld(false)]
#[Description('Copy the posting schedule of another channel of the workspace onto this one. The source must have a schedule. Queued posts whose slot no longer exists are re-placed. Only workspace admins can copy it.')]
class CopyPostingScheduleTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request, CopyPostingSchedule $copy): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageAccounts');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $account = SocialAccount::where('workspace_id', $workspace->id)->find(data_get($request->validate(['account_id' => ['required', 'string', 'uuid']]), 'account_id'));

        if (! $account) {
            return Response::error('Social account not found.');
        }

        $source = SocialAccount::where('workspace_id', $workspace->id)->find(data_get($request->validate(['from' => ['required', 'uuid']]), 'from'));

        if (! $source) {
            return Response::error('Social account not found.');
        }

        try {
            $account = $copy->handle($account, $source);
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        return Response::structured((new ChannelPostingScheduleResource($account))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the channel that receives the schedule.'),
            'from' => $schema->string()->required()->description('The UUID of the channel whose schedule is copied.'),
        ];
    }
}
