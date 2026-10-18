<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Inbox\RetryInboxSync;
use App\Http\Requests\Api\Inbox\StoreInboxChannelSyncRequest;
use App\Models\SocialAccount;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InboxChannelSyncController extends Controller
{
    public function store(StoreInboxChannelSyncRequest $request, SocialAccount $socialAccount): Response
    {
        RetryInboxSync::execute($socialAccount, $request->kind());

        return response()->noContent(HttpResponse::HTTP_ACCEPTED);
    }
}
