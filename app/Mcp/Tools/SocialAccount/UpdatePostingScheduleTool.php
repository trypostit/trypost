<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\UpdatePostingSchedule;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\ChannelPostingScheduleResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use Closure;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Replace the posting schedule of a channel: time zone, weekly posting goal and the seven days (day 0 is Sunday) with their posting times: at most 4 unique HH:mm times per day, in the channel time zone. A null posting_schedule clears it. Queued posts whose slot no longer exists are re-placed into the first free slots, and a time zone change moves every queued post to the same local clock time in the new zone. Only workspace admins can edit it.')]
class UpdatePostingScheduleTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request, UpdatePostingSchedule $update): Response|ResponseFactory
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
            'timezone' => ['required', 'string', 'timezone:all'],
            'posting_goal' => ['nullable', 'integer', 'min:1', 'max:'.PostingSchedule::MAX_GOAL],
            'posting_schedule' => ['nullable', 'array', 'size:7'],
            'posting_schedule.*.day' => ['required', 'integer', 'between:0,6', 'distinct'],
            'posting_schedule.*.enabled' => ['required', 'boolean'],
            'posting_schedule.*.times' => ['present', 'array', 'max:'.PostingSchedule::MAX_TIMES_PER_DAY],
            'posting_schedule.*.times.*' => ['required', 'date_format:H:i', $this->uniqueWithinDay($request->all())],
        ]);

        try {
            $account = $update->handle($account, $validated);
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        return Response::structured((new ChannelPostingScheduleResource($account))->resolve());
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function uniqueWithinDay(array $input): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($input): void {
            $times = data_get($input, Str::beforeLast($attribute, '.'));

            if (is_array($times) && count(array_keys($times, $value, true)) > 1) {
                $fail("The {$attribute} field has a duplicate value.");
            }
        };
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected social account.'),
            'timezone' => $schema->string()->required()->description('IANA time zone of the channel, e.g. America/Sao_Paulo.'),
            'posting_goal' => $schema->integer()->nullable()->description('Weekly posting goal, 1 to 28 posts, or null.'),
            'posting_schedule' => $schema->array()->nullable()->items($schema->object([
                'day' => $schema->integer()->required()->description('0 (Sunday) to 6 (Saturday).'),
                'enabled' => $schema->boolean()->required(),
                'times' => $schema->array()->items($schema->string())->required()->description('At most 4 unique HH:mm times of that day, in the channel time zone.'),
            ]))->description('Exactly seven days, or null to clear the schedule.'),
        ];
    }
}
