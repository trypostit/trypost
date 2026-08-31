<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Events\TelegramChannelConnected;
use App\Events\TelegramConnectFailed;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\Telegram\TelegramApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

class ConnectTelegramChannel
{
    /**
     * Link a Telegram chat to a workspace for a one-off connect nonce.
     *
     * @param  array<string, mixed>  $chat  The `chat` object from the Bot API update.
     * @return SocialAccount|null The linked account, or null when blocked (account
     *                            limit reached or the code was already consumed).
     */
    public static function execute(Workspace $workspace, array $chat, string $nonce, mixed $reconnectId = null): ?SocialAccount
    {
        $chatId = (string) data_get($chat, 'id');
        $username = data_get($chat, 'username');
        $reconnect = is_string($reconnectId)
            ? $workspace->socialAccounts()
                ->whereIn('platform', Platform::Telegram->networkPlatformValues())
                ->find($reconnectId)
            : null;

        $isNewAccount = ! $workspace->socialAccounts()
            ->where('platform', Platform::Telegram->value)
            ->where('platform_user_id', $chatId)
            ->exists();

        if ($reconnect === null && $isNewAccount && SocialAccount::occupiesNetwork((string) $workspace->id, Platform::Telegram)) {
            TelegramConnectFailed::dispatch($workspace->id, $nonce, 'network_taken');

            return null;
        }

        // Reject before consuming the nonce so the user can retry in the right
        // chat with the code they already have.
        if ($reconnect !== null && (string) $reconnect->platform_user_id !== $chatId) {
            TelegramConnectFailed::dispatch($workspace->id, $nonce, 'wrong_chat');

            return null;
        }

        // Consume the code once so a leaked code can't be replayed to link another chat.
        if (! Cache::add("telegram:connect:{$nonce}", true, now()->addMinutes(15))) {
            return null;
        }

        try {
            $account = SocialAccount::connectIdentity(
                $workspace,
                Platform::Telegram,
                $chatId,
                [
                    'username' => $username,
                    'display_name' => data_get($chat, 'title') ?? $username ?? "Telegram {$chatId}",
                    'avatar_url' => self::fetchChannelAvatar($chatId),
                    'access_token' => '',
                    'refresh_token' => '',
                    'token_expires_at' => null,
                    'scopes' => [],
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'chat_id' => $chatId,
                        'username' => $username,
                        'type' => data_get($chat, 'type'),
                        'connect_nonce' => $nonce,
                    ],
                ],
                $reconnect,
            );
        } catch (NetworkAlreadyConnectedException $e) {
            // The nonce is already spent, so letting a busy lock reach the
            // webhook would 500 to Telegram and its retry would short-circuit
            // on the consumed code, leaving the dialog spinning with no error.
            TelegramConnectFailed::dispatch($workspace->id, $nonce, $e->messageKey);

            return null;
        }

        TelegramChannelConnected::dispatch($workspace->id, $nonce);

        return $account;
    }

    /**
     * Download the channel's photo via the Bot API and store it, returning the path.
     */
    private static function fetchChannelAvatar(string $chatId): ?string
    {
        if (TelegramApi::token() === '') {
            return null;
        }

        try {
            $fileId = data_get(Http::get(TelegramApi::endpoint('getChat'), ['chat_id' => $chatId])->json(), 'result.photo.big_file_id');

            if (! is_string($fileId)) {
                return null;
            }

            $filePath = data_get(Http::get(TelegramApi::endpoint('getFile'), ['file_id' => $fileId])->json(), 'result.file_path');

            if (! is_string($filePath)) {
                return null;
            }

            return uploadFromUrl(TelegramApi::fileUrl($filePath));
        } catch (Throwable) {
            return null;
        }
    }
}
