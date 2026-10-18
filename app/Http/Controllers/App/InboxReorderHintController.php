<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\SocialAccount\Platform;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InboxReorderHintController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless(Platform::inboxModuleEnabled(), HttpResponse::HTTP_NOT_FOUND);

        $user = $request->user();

        if (blank($user->inbox_reorder_hint_seen_at)) {
            $user->forceFill(['inbox_reorder_hint_seen_at' => now()])->save();
        }

        return back();
    }
}
