<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\RegeneratePostingSchedule;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\ChannelPostingScheduleResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Generate the posting schedule of a channel from the recommended windows of its network, for a number of posts per week (goal). Replaces the current schedule; mode is accepted for parity but has no effect today (both values generate from the goal over the network windows); queued posts whose slot no longer exists are re-placed. Only workspace admins can generate it.')]
class GeneratePostingScheduleTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request, RegeneratePostingSchedule $regenerate): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageAccounts');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $account = SocialAccount::where('workspace_id', $workspace->id)->find(data_get($request->validate(['account_id' => ['required', 'string', 'uuid']]), 'account_id'));

        if (! $account) {
            return Response::error('Social account not found.');
        }

        $validated = $request->validate([
            'mode' => ['required', Rule::in(['goal', 'recommended'])],
            'goal' => ['nullable', 'integer', 'min:1', 'max:'.PostingSchedule::MAX_GOAL],
        ]);

        $goal = data_get($validated, 'goal');
        try {
            $account = $regenerate->handle($account, $goal === null ? null : (int) $goal);
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        return Response::structured((new ChannelPostingScheduleResource($account))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected social account.'),
            'mode' => $schema->string()->enum(['goal', 'recommended'])->required()->description('goal or recommended; accepted for parity, both generate from the goal.'),
            'goal' => $schema->integer()->nullable()->description('Posts per week, 1 to 28; defaults to the channel goal, else 3.'),
        ];
    }
}
