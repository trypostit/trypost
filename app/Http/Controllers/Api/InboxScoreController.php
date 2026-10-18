<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\CalculateInboxScore;
use App\Dto\Inbox\InboxFilters;
use App\Http\Requests\Api\Inbox\ShowInboxScoreRequest;
use App\Http\Resources\Api\InboxScoreResource;

class InboxScoreController extends Controller
{
    public function show(ShowInboxScoreRequest $request): InboxScoreResource
    {
        $user = $request->user();

        return InboxScoreResource::make(CalculateInboxScore::cached(
            $user->currentWorkspace,
            $user,
            InboxFilters::fromArray($request->validated())->channelIds,
        ));
    }
}
