<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Inbox\ResolveInboxMessages;
use App\Actions\Inbox\UnresolveInboxMessage;
use App\Enums\Inbox\ResolveScope;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\App\Inbox\StoreInboxResolutionRequest;
use App\Models\InboxMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InboxResolutionController extends Controller
{
    public function store(StoreInboxResolutionRequest $request): RedirectResponse
    {
        $user = $request->user();

        ResolveInboxMessages::execute(
            $user->currentWorkspace,
            $user,
            ResolveScope::from($request->validated('scope')),
            $request->message(),
            $request->conversation(),
            $request->channelIds(),
            $request->tab(),
        );

        return back();
    }

    public function destroy(Request $request, InboxMessage $message): RedirectResponse
    {
        abort_unless(Platform::inboxModuleEnabled(), HttpResponse::HTTP_NOT_FOUND);

        $this->authorize('createPost', $request->user()->currentWorkspace);
        abort_unless($message->conversation->workspace_id === $request->user()->currentWorkspace?->id, HttpResponse::HTTP_NOT_FOUND);
        $this->authorize('resolve', $message);

        UnresolveInboxMessage::execute($message);

        return back();
    }
}
