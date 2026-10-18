<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\ListConversationThreads;
use App\Actions\Inbox\ListInboxConversations;
use App\Actions\Inbox\ShowInboxConversation;
use App\Dto\Inbox\InboxFilters;
use App\Enums\SocialAccount\Platform;
use App\Http\Requests\Api\Inbox\IndexInboxRequest;
use App\Http\Requests\Api\Inbox\ShowInboxConversationRequest;
use App\Http\Resources\Api\InboxConversationPageResource;
use App\Http\Resources\Api\InboxConversationResource;
use App\Models\InboxConversation;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class InboxConversationController extends Controller
{
    public function index(IndexInboxRequest $request): AnonymousResourceCollection
    {
        $conversations = ListInboxConversations::paginate(
            $request->user()->currentWorkspace,
            InboxFilters::fromArray($request->validated()),
        );

        return InboxConversationResource::collection($conversations);
    }

    /**
     * The conversation with one page of its top-level messages (newest first),
     * each carrying its reply tree, paged with the app page size.
     */
    public function show(ShowInboxConversationRequest $request, InboxConversation $conversation): InboxConversationPageResource
    {
        abort_unless(Platform::inboxModuleEnabled(), Response::HTTP_NOT_FOUND);
        abort_unless($conversation->workspace_id === $request->user()->currentWorkspace?->id, Response::HTTP_NOT_FOUND);

        Gate::authorize('view', $conversation);

        $threads = ListConversationThreads::execute($conversation)->paginate((int) config('app.pagination.default'));

        return new InboxConversationPageResource(ShowInboxConversation::execute($conversation), $threads);
    }
}
