<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Services\Social\Telegram\TelegramConnectCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TelegramController extends SocialController
{
    protected SocialPlatform $platform = SocialPlatform::Telegram;

    /**
     * Start a connection: issue a signed one-off code the user posts in their
     * channel (`/connect <code>`). The code carries the workspace, so the webhook
     * can link the channel without any persisted state. The returned `nonce` lets
     * the UI recognise its own connection on the broadcast channel.
     */
    public function connect(Request $request): JsonResponse
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;
        abort_if($workspace === null, SymfonyResponse::HTTP_CONFLICT, 'No active workspace.');

        $this->authorize('manageAccounts', $workspace);

        $expiresAt = now()->addMinutes(15);
        $code = TelegramConnectCode::issue($workspace->id, $expiresAt, $this->validatedReconnectId($request, $workspace));

        return response()->json([
            'code' => $code,
            'nonce' => data_get(TelegramConnectCode::decode($code), 'nonce'),
            'bot_username' => config('trypost.platforms.telegram.bot_username'),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }
}
