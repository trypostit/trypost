<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\SocialAccount\ExpireMetaUserChannels;
use App\Enums\SocialAccount\MetaApp;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Social\Meta\SignedRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class MetaDeauthorizeController extends Controller
{
    /**
     * Meta calls this when someone removes one of the install's Meta apps from
     * their account. Every token that app gave them is dead, so their channels
     * are flagged for reconnection at once, and each workspace owner is told,
     * instead of waiting for the next publish or the daily check to find out.
     */
    public function __invoke(Request $request, string $app): Response
    {
        $metaApp = MetaApp::from($app);
        $userId = SignedRequest::userId((string) $request->input('signed_request'), $metaApp->secret());

        abort_if($userId === null, SymfonyResponse::HTTP_FORBIDDEN);

        ExpireMetaUserChannels::execute(
            SocialAccount::query()->authorizedByMetaUser($metaApp, $userId)->with('workspace.owner')->get(),
            'The app was removed from the account on Meta',
        );

        return response()->noContent();
    }
}
