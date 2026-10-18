<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Inbox\BuildInboxPageProps;
use App\Dto\Inbox\InboxFilters;
use App\Enums\Inbox\InboxTab;
use App\Enums\SocialAccount\Platform;
use App\Http\Controllers\App\Concerns\ScopesInboxToChannel;
use App\Http\Requests\App\Inbox\IndexInboxRequest;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InboxMessageController extends Controller
{
    use ScopesInboxToChannel;

    public function index(IndexInboxRequest $request, ?SocialAccount $account = null): Response|RedirectResponse
    {
        abort_unless(Platform::inboxModuleEnabled(), HttpResponse::HTTP_NOT_FOUND);

        if ($request->validated('tab') === InboxTab::Messages->value) {
            return $this->inboxRedirect('app.inbox.index', $request->query(), $account);
        }

        $workspace = $request->user()->currentWorkspace;
        $this->authorize('createPost', $workspace);

        return Inertia::render('inbox/Index', BuildInboxPageProps::execute(
            $request->user(),
            $workspace,
            $this->scopedFilters($request, InboxFilters::fromArray($request->validated()), $account),
            'list',
            messagesAround: $request->validated('messages_around'),
        ));
    }
}
