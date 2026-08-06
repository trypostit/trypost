<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\Discord\IndexDiscordMentionRequest;
use App\Models\SocialAccount;
use App\Services\Social\Discord\DiscordClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class DiscordController extends Controller
{
    public function __construct(private DiscordClient $discord) {}

    public function channels(Request $request, SocialAccount $account): JsonResponse
    {
        $this->authorizeDiscordAccount($request, $account);

        // Degrade to an empty list on a transient Discord outage — the picker
        // shows "no channels" rather than erroring; the publish path still
        // retries (channels() throws there).
        return response()->json([
            'channels' => rescue(
                fn () => $this->discord->channels((string) $account->platform_user_id),
                [],
                report: false,
            ),
        ]);
    }

    public function mentions(IndexDiscordMentionRequest $request, SocialAccount $account): JsonResponse
    {
        $this->authorizeDiscordAccount($request, $account);

        return response()->json([
            'mentions' => $this->discord->mentions(
                (string) $account->platform_user_id,
                $request->validated('q', ''),
            ),
        ]);
    }

    private function authorizeDiscordAccount(Request $request, SocialAccount $account): void
    {
        $workspace = $request->user()->currentWorkspace;

        abort_unless(
            $workspace && $account->workspace_id === $workspace->id && $account->platform === SocialPlatform::Discord,
            Response::HTTP_FORBIDDEN,
        );

        $this->authorize('view', $workspace);
    }
}
