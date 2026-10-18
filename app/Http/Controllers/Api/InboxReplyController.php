<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\ReplyToInboxMessage;
use App\Actions\Inbox\RetryInboxReply;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\Api\Inbox\StoreInboxReplyRequest;
use App\Http\Resources\Api\InboxMessageResource;
use App\Models\InboxMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class InboxReplyController extends Controller
{
    public function store(StoreInboxReplyRequest $request, InboxMessage $message): JsonResponse
    {
        $reply = ReplyToInboxMessage::execute($message, $request->user(), $request->validated('text'));

        return (new InboxMessageResource($reply))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function retry(Request $request, InboxMessage $message): InboxMessageResource
    {
        abort_unless(Platform::inboxModuleEnabled(), Response::HTTP_NOT_FOUND);
        abort_unless($message->conversation->workspace_id === $request->user()->currentWorkspace?->id, Response::HTTP_NOT_FOUND);

        Gate::authorize('reply', $message->parent ?? $message);

        return new InboxMessageResource(RetryInboxReply::execute($message));
    }
}
