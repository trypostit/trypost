<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\SendInboxDirectMessage;
use App\Http\Requests\Api\Inbox\StoreInboxConversationMessageRequest;
use App\Http\Resources\Api\InboxMessageResource;
use App\Models\InboxConversation;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class InboxConversationMessageController extends Controller
{
    public function store(StoreInboxConversationMessageRequest $request, InboxConversation $conversation): JsonResponse
    {
        $message = SendInboxDirectMessage::execute($conversation, $request->user(), $request->validated('text'));

        return (new InboxMessageResource($message))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
