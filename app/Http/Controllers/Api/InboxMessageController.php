<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\ListInboxMessages;
use App\Dto\Inbox\InboxFilters;
use App\Http\Requests\Api\Inbox\IndexInboxRequest;
use App\Http\Resources\Api\InboxMessageResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InboxMessageController extends Controller
{
    public function index(IndexInboxRequest $request): AnonymousResourceCollection
    {
        $messages = ListInboxMessages::execute(
            $request->user()->currentWorkspace,
            InboxFilters::fromArray($request->validated()),
        )->paginate((int) config('app.pagination.default'));

        return InboxMessageResource::collection($messages);
    }
}
