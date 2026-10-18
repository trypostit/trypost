<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Inbox\ReplyToInboxMessage;
use App\Actions\Inbox\RetryInboxReply;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\App\Inbox\StoreInboxReplyRequest;
use App\Models\InboxMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InboxReplyController extends Controller
{
    public function store(StoreInboxReplyRequest $request, InboxMessage $message): RedirectResponse
    {
        ReplyToInboxMessage::execute($message, $request->user(), $request->validated('text'));

        return back();
    }

    public function retry(Request $request, InboxMessage $message): RedirectResponse
    {
        abort_unless(Platform::inboxModuleEnabled(), HttpResponse::HTTP_NOT_FOUND);
        $this->authorize('createPost', $request->user()->currentWorkspace);
        abort_unless($message->conversation->workspace_id === $request->user()->currentWorkspace?->id, HttpResponse::HTTP_NOT_FOUND);

        $this->authorize('reply', $message->parent ?? $message);

        RetryInboxReply::execute($message);

        return back();
    }
}
