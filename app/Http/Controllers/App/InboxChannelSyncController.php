<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Inbox\RetryInboxSync;
use App\Http\Requests\App\Inbox\StoreInboxChannelSyncRequest;
use App\Models\SocialAccount;
use Illuminate\Http\RedirectResponse;

class InboxChannelSyncController extends Controller
{
    public function store(StoreInboxChannelSyncRequest $request, SocialAccount $socialAccount): RedirectResponse
    {
        RetryInboxSync::execute($socialAccount, $request->kind());

        return back();
    }
}
