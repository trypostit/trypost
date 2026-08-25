<?php

declare(strict_types=1);

namespace App\Services\Social\Telegram;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * A stateless, signed `/connect` code. It carries the workspace it belongs to,
 * a one-off nonce (so the polling UI can recognise its own connection) and an
 * expiry — encrypted with the app key, so the webhook can trust it without any
 * database lookup.
 */
class TelegramConnectCode
{
    public static function issue(string $workspaceId, CarbonInterface $expiresAt, ?string $reconnectId = null): string
    {
        return Crypt::encryptString((string) json_encode([
            'workspace_id' => $workspaceId,
            'nonce' => Str::lower(Str::random(16)),
            'expires_at' => $expiresAt->getTimestamp(),
            'reconnect_id' => $reconnectId,
        ]));
    }

    /**
     * Decode and validate a code, returning its payload or null when the code is
     * missing, tampered with, malformed, or expired.
     *
     * @return array{workspace_id: string, nonce: string, expires_at: int, reconnect_id: string|null}|null
     */
    public static function decode(mixed $code): ?array
    {
        if (! is_string($code) || $code === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($code), true);
        } catch (DecryptException) {
            return null;
        }

        if (
            ! is_array($payload)
            || ! is_string(data_get($payload, 'workspace_id'))
            || ! is_string(data_get($payload, 'nonce'))
            || now()->getTimestamp() > (int) data_get($payload, 'expires_at')
        ) {
            return null;
        }

        return $payload;
    }
}
