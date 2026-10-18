<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\ResolveInboxMessages;
use App\Actions\Inbox\UnresolveInboxMessage;
use App\Enums\Inbox\ResolveScope;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\Api\Inbox\StoreInboxResolutionRequest;
use App\Http\Resources\Api\InboxMessageResource;
use App\Http\Resources\Api\InboxResolutionResource;
use App\Models\InboxMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class InboxResolutionController extends Controller
{
    public function store(StoreInboxResolutionRequest $request): InboxResolutionResource
    {
        $user = $request->user();

        $resolved = ResolveInboxMessages::execute(
            $user->currentWorkspace,
            $user,
            ResolveScope::from($request->validated('scope')),
            $request->message(),
            $request->conversation(),
            $request->channelIds(),
            $request->tab(),
        );

        return new InboxResolutionResource($resolved);
    }

    public function destroy(Request $request, InboxMessage $message): InboxMessageResource
    {
        abort_unless(Platform::inboxModuleEnabled(), Response::HTTP_NOT_FOUND);
        abort_unless($message->conversation->workspace_id === $request->user()->currentWorkspace?->id, Response::HTTP_NOT_FOUND);

        Gate::authorize('resolve', $message);

        return new InboxMessageResource(UnresolveInboxMessage::execute($message));
    }
}
