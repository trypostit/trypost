<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\Queue\MoveChannelPostToQueueSlot;
use App\Actions\Post\Queue\ReorderChannelQueue;
use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Http\Requests\App\Channel\MoveChannelPostToQueueSlotRequest;
use App\Http\Requests\App\Channel\ReorderChannelQueueRequest;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;

class ChannelQueueController extends Controller
{
    use EnsuresChannelInCurrentWorkspace;

    public function reorder(ReorderChannelQueueRequest $request, SocialAccount $account): RedirectResponse
    {
        $this->ensureCurrentWorkspace($request, $account);
        $this->authorize('publishDirectly', $request->user()->currentWorkspace);

        ReorderChannelQueue::handle($account, $request->validated('post_ids'));

        return back();
    }

    public function moveToSlot(MoveChannelPostToQueueSlotRequest $request, SocialAccount $account): RedirectResponse
    {
        $this->ensureCurrentWorkspace($request, $account);
        $this->authorize('publishDirectly', $request->user()->currentWorkspace);

        MoveChannelPostToQueueSlot::handle(
            $account,
            $request->validated('post_id'),
            CarbonImmutable::parse($request->validated('slot_at'))->utc(),
            $request->user(),
        );

        return back();
    }
}
