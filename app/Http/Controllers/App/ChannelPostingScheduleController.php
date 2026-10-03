<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Actions\SocialAccount\GeneratePostingSchedule;
use App\Http\Requests\App\Channel\CopyPostingScheduleRequest;
use App\Http\Requests\App\Channel\GeneratePostingScheduleRequest;
use App\Http\Requests\App\Channel\UpdatePostingScheduleRequest;
use App\Http\Resources\App\ChannelPostingScheduleResource;
use App\Models\SocialAccount;
use App\Support\PostingSchedule;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ChannelPostingScheduleController extends Controller
{
    public function update(UpdatePostingScheduleRequest $request, SocialAccount $account): ChannelPostingScheduleResource
    {
        $this->authorizeChannel($request, $account);

        $schedule = $request->validated('posting_schedule');
        $previousTimezone = $account->timezone;

        $account->update([
            'timezone' => $request->validated('timezone'),
            'posting_goal' => $request->validated('posting_goal'),
            'posting_schedule' => $schedule === null ? null : PostingSchedule::fromArray($schedule),
        ]);

        ReflowChannelQueue::afterCommit($account->id, $previousTimezone);

        return ChannelPostingScheduleResource::make($account);
    }

    public function generate(GeneratePostingScheduleRequest $request, SocialAccount $account, GeneratePostingSchedule $generate): ChannelPostingScheduleResource
    {
        $this->authorizeChannel($request, $account);

        $goal = (int) ($request->validated('goal') ?? $account->posting_goal ?? 3);

        $account->update([
            'posting_goal' => $goal,
            'posting_schedule' => $generate->handle($account->platform, $goal),
        ]);

        ReflowChannelQueue::afterCommit($account->id);

        return ChannelPostingScheduleResource::make($account);
    }

    public function copy(CopyPostingScheduleRequest $request, SocialAccount $account): ChannelPostingScheduleResource
    {
        $this->authorizeChannel($request, $account);

        $source = $request->user()->currentWorkspace->socialAccounts()->findOrFail($request->validated('from'));

        if ($source->posting_schedule === null) {
            throw ValidationException::withMessages(['from' => trans('validation.exists', ['attribute' => 'from'])]);
        }

        $account->update(['posting_schedule' => $source->posting_schedule]);

        ReflowChannelQueue::afterCommit($account->id);

        return ChannelPostingScheduleResource::make($account);
    }

    private function authorizeChannel(Request $request, SocialAccount $account): void
    {
        abort_unless($account->workspace_id === $request->user()->current_workspace_id, HttpResponse::HTTP_NOT_FOUND);

        $this->authorize('manageAccounts', $request->user()->currentWorkspace);
    }
}
