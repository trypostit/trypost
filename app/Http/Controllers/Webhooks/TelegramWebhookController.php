<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\SocialAccount\ConnectTelegramChannel;
use App\Actions\SocialAccount\StoreTelegramReactions;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Services\Social\Telegram\TelegramConnectCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class TelegramWebhookController extends Controller
{
    /**
     * Receives Bot API updates. The only update we act on is a `/connect <code>`
     * message/channel_post: the signed code carries the workspace, so we link the
     * originating channel to it. Everything else is acknowledged and ignored.
     */
    public function handle(Request $request): Response
    {
        $secret = (string) config('trypost.platforms.telegram.webhook_secret');

        abort_if(
            $secret === '' || ! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')),
            SymfonyResponse::HTTP_FORBIDDEN,
        );

        $update = $request->all();

        if (is_array($reactionUpdate = data_get($update, 'message_reaction_count'))) {
            StoreTelegramReactions::execute($reactionUpdate);

            return response()->noContent();
        }

        $chat = data_get($update, 'message.chat') ?? data_get($update, 'channel_post.chat');
        $text = data_get($update, 'message.text') ?? data_get($update, 'channel_post.text');

        if (! is_array($chat) || ! is_string($text) || ! preg_match('/^\/connect(?:@\S+)?\s+(\S+)/', $text, $matches)) {
            return response()->noContent();
        }

        $payload = TelegramConnectCode::decode($matches[1]);
        $workspace = $payload === null ? null : Workspace::find(data_get($payload, 'workspace_id'));

        if ($workspace !== null) {
            ConnectTelegramChannel::execute(
                $workspace,
                $chat,
                data_get($payload, 'nonce'),
                data_get($payload, 'reconnect_id'),
            );
        }

        return response()->noContent();
    }
}
