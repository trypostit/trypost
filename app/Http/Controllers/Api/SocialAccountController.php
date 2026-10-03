<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\SocialAccount\ListDiscordChannels;
use App\Actions\SocialAccount\ListPinterestBoards;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\PinterestPublishException;
use App\Exceptions\TokenExpiredException;
use App\Http\Resources\Api\SocialAccountResource;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class SocialAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = $request->user()->currentWorkspace->socialAccounts()->get();

        return SocialAccountResource::collection($accounts);
    }

    public function boards(Request $request, SocialAccount $account): JsonResponse
    {
        $this->authorize('view', $account);

        if ($account->platform !== Platform::Pinterest) {
            return response()->json(
                ['message' => 'Boards are only available for Pinterest accounts.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            return response()->json(ListPinterestBoards::execute($account));
        } catch (TokenExpiredException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_UNAUTHORIZED,
            );
        } catch (PinterestPublishException $e) {
            return response()->json(
                ['message' => $e->userMessage],
                $this->statusForPinterestCategory($e->category),
            );
        }
    }

    public function channels(Request $request, SocialAccount $account): JsonResponse
    {
        $this->authorize('view', $account);

        if ($account->platform !== Platform::Discord) {
            return response()->json(
                ['message' => 'Channels are only available for Discord accounts.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        try {
            return response()->json([
                'channels' => ListDiscordChannels::execute($account),
            ]);
        } catch (PlatformUnavailableException $e) {
            return response()->json(
                ['message' => $e->getMessage()],
                Response::HTTP_BAD_GATEWAY,
            );
        }
    }

    private function statusForPinterestCategory(ErrorCategory $category): int
    {
        return match ($category) {
            ErrorCategory::RateLimit => Response::HTTP_TOO_MANY_REQUESTS,
            ErrorCategory::Permission => Response::HTTP_FORBIDDEN,
            default => Response::HTTP_BAD_GATEWAY,
        };
    }
}
