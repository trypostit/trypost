<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Inbox\SendInboxDirectMessage;
use App\Http\Requests\App\Inbox\StoreInboxConversationMessageRequest;
use App\Models\InboxConversation;
use Illuminate\Http\RedirectResponse;

class InboxConversationMessageController extends Controller
{
    public function store(StoreInboxConversationMessageRequest $request, InboxConversation $conversation): RedirectResponse
    {
        SendInboxDirectMessage::execute($conversation, $request->user(), $request->validated('text'));

        return back();
    }
}
