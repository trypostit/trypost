<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Post\Queue\MoveChannelPostToQueueSlot;
use App\Actions\Post\Queue\ReorderChannelQueue;
use App\Http\Requests\Api\Channel\MoveChannelPostToQueueSlotRequest;
use App\Http\Requests\Api\Channel\ReorderChannelQueueRequest;
use App\Http\Resources\Api\PostResource;
use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response as EmptyResponse;

class ChannelQueueController extends Controller
{
    public function reorder(ReorderChannelQueueRequest $request, SocialAccount $account): EmptyResponse
    {
        ReorderChannelQueue::handle($account, $request->validated('post_ids'));

        return response()->noContent();
    }

    public function moveToSlot(MoveChannelPostToQueueSlotRequest $request, SocialAccount $account): PostResource
    {
        MoveChannelPostToQueueSlot::handle(
            $account,
            $request->validated('post_id'),
            CarbonImmutable::parse($request->validated('slot_at'))->utc(),
            $request->user(),
        );

        $post = Post::query()->with(['socialAccount', 'labels'])->findOrFail($request->validated('post_id'));

        return new PostResource($post);
    }
}
