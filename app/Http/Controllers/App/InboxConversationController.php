<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Inbox\BuildInboxPageProps;
use App\Actions\Inbox\ListConversationThreads;
use App\Actions\Inbox\ListInboxPosts;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\ConversationKind;
use App\Enums\Inbox\InboxTab;
use App\Enums\Inbox\StatusFilter;
use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\App\Concerns\ScopesInboxToChannel;
use App\Http\Requests\App\Inbox\IndexInboxRequest;
use App\Models\InboxConversation;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Inbox\InboxDrivers;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InboxConversationController extends Controller
{
    use ScopesInboxToChannel;

    public function index(IndexInboxRequest $request, ?SocialAccount $account = null): Response|RedirectResponse
    {
        abort_unless(Platform::inboxModuleEnabled(), HttpResponse::HTTP_NOT_FOUND);

        $workspace = $request->user()->currentWorkspace;
        $this->authorize('createPost', $workspace);

        $filters = $this->scopedFilters($request, InboxFilters::fromArray($request->validated()), $account);

        if ($filters->tab === InboxTab::Messages && ! BuildInboxPageProps::directMessagesOn($workspace, $filters)) {
            return $this->inboxRedirect('app.inbox.index', Arr::except($request->query(), ['tab', 'thread_conversation', BuildInboxPageProps::DIRECT_MESSAGES_PAGE_NAME]), $account);
        }

        if ($filters->tab === InboxTab::Mentions || ($filters->tab === InboxTab::Comments && $this->isReviewOnly($workspace, $filters->channelIds))) {
            return $this->inboxRedirect('app.inbox.messages.index', Arr::except($request->query(), ['thread_conversation', 'thread_around', ListConversationThreads::PAGE_NAME]), $account);
        }

        return Inertia::render('inbox/Index', BuildInboxPageProps::execute(
            $request->user(),
            $workspace,
            $filters,
            'post',
            $this->scrolledConversation($workspace, $filters, $request->validated('thread_conversation'))
                ?? $this->pickedPost($workspace, $filters, $request->validated('post')),
            $request->validated('thread_around'),
        ));
    }

    public function show(IndexInboxRequest $request, InboxConversation $conversation): Response|RedirectResponse
    {
        return $this->renderConversation($request, $conversation, null);
    }

    /**
     * A conversation on its channel's own inbox (channels/{account}/inbox/conversations/{conversation}).
     */
    public function showInChannel(IndexInboxRequest $request, SocialAccount $account, InboxConversation $conversation): Response|RedirectResponse
    {
        return $this->renderConversation($request, $conversation, $account);
    }

    private function renderConversation(IndexInboxRequest $request, InboxConversation $conversation, ?SocialAccount $account): Response|RedirectResponse
    {
        abort_unless(Platform::inboxModuleEnabled(), HttpResponse::HTTP_NOT_FOUND);

        $user = $request->user();

        $this->authorize('createPost', $user->currentWorkspace);

        $this->authorize('view', $conversation);

        if ($conversation->workspace_id !== $user->current_workspace_id) {
            abort_unless($user->belongsToWorkspace($conversation->workspace), HttpResponse::HTTP_NOT_FOUND);

            $user->switchWorkspace($conversation->workspace);

            return redirect()->to($request->fullUrl());
        }

        abort_if($account && $conversation->social_account_id !== $account->id, HttpResponse::HTTP_NOT_FOUND);

        if ($conversation->kind === ConversationKind::Review) {
            return $this->inboxRedirect('app.inbox.messages.index', [
                'channels' => [$conversation->social_account_id],
                'status' => StatusFilter::All->value,
            ], $account);
        }

        if ($conversation->kind === ConversationKind::Mention) {
            return $this->inboxRedirect('app.inbox.messages.index', [
                'tab' => InboxTab::Mentions->value,
                'channels' => [$conversation->social_account_id],
                'status' => StatusFilter::All->value,
            ], $account);
        }

        $workspace = $user->currentWorkspace;
        $filters = $this->scopedFilters($request, InboxFilters::fromArray([...$request->validated(), 'tab' => InboxTab::forKind($conversation->kind)->value]), $account);

        return Inertia::render('inbox/Index', BuildInboxPageProps::execute(
            $user,
            $workspace,
            $filters,
            'post',
            $conversation,
            $request->validated('thread_around'),
        ));
    }

    /**
     * The next pages of the open post's comments (or the open conversation's
     * messages) name it, so they keep to it even when the list's first
     * conversation has changed meanwhile.
     */
    private function scrolledConversation(Workspace $workspace, InboxFilters $filters, mixed $conversationId): ?InboxConversation
    {
        $kind = $filters->tab === InboxTab::Messages ? ConversationKind::DirectMessage : ConversationKind::Comment;

        return filled($conversationId)
            ? InboxConversation::query()->visibleIn($workspace)->where('kind', $kind)->find($conversationId)
            : null;
    }

    /**
     * A post picked in "Show all posts": its comment conversation, or the
     * post on its own while nobody has commented on it.
     */
    private function pickedPost(Workspace $workspace, InboxFilters $filters, mixed $postId): ?InboxConversation
    {
        return filled($postId) && $filters->tab === InboxTab::Comments
            ? ListInboxPosts::forPost($workspace, (string) $postId)
            : null;
    }

    /**
     * By post lists comments only, so channels that all have reviews only,
     * or a workspace whose inbox channels all have reviews only, open the
     * List view, wherever the link came from.
     *
     * @param  list<string>  $channelIds
     */
    private function isReviewOnly(Workspace $workspace, array $channelIds): bool
    {
        $drivers = app(InboxDrivers::class);
        $channels = $workspace->socialAccounts()
            ->whereIn('platform', Platform::inboxValues())
            ->when(filled($channelIds), fn ($query) => $query->whereKey($channelIds))
            ->get(['id', 'platform']);

        return $channels->isNotEmpty() && $channels->every(function (SocialAccount $account) use ($drivers): bool {
            $kinds = $drivers->for($account->platform)?->kinds() ?? [];

            return in_array(ConversationKind::Review, $kinds, true) && ! in_array(ConversationKind::Comment, $kinds, true);
        });
    }
}
