<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use Illuminate\Http\Client\Response;

class TelegramPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $status = $response->status();
        $rawResponse = $response->body();
        $description = (string) data_get($response->json(), 'description', 'An unknown Telegram error occurred.');

        // 403: the bot was removed or isn't an admin of the channel anymore.
        if ($status === 403) {
            return new static(
                userMessage: 'The bot is not an admin of this channel. Re-add it as an administrator and try again.',
                category: ErrorCategory::Permission,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        // 401: the configured bot token is invalid (operator-level misconfiguration).
        if ($status === 401) {
            return new static(
                userMessage: 'Telegram rejected the bot token. Check the TELEGRAM_BOT_TOKEN configuration.',
                category: ErrorCategory::Permission,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status === 429) {
            return new static(
                userMessage: 'Telegram rate limit reached. Please try again shortly.',
                category: ErrorCategory::RateLimit,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        if ($status >= 500) {
            return new static(
                userMessage: 'Telegram is temporarily unavailable. Please try again later.',
                category: ErrorCategory::ServerError,
                platformErrorCode: (string) $status,
                rawResponse: $rawResponse,
            );
        }

        return new static(
            userMessage: $description,
            category: ErrorCategory::Unknown,
            platformErrorCode: (string) $status,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'telegram';
    }

    /**
     * Whether this response confirms the bot lost access to THIS specific
     * chat (kicked, blocked, or the chat was deleted) — used by
     * ConnectionVerifier against getChat's response.
     *
     * Deliberately NOT the same check fromApiResponse() uses above, and not
     * called from it: fromApiResponse() classifies sendMessage/sendPhoto
     * responses (message-send scope, where a 403 there stays a
     * Permission-category publish failure rather than disconnecting the
     * account — the bot could still reach other chats fine), while this
     * classifies getChat responses (chat-read scope, where the same 400/403
     * unambiguously means this one chat is gone). 401 is excluded from both:
     * Telegram auth is one bot token shared across every connected account,
     * so a 401 means that shared token is misconfigured (an operator
     * problem), never evidence that this specific chat connection is dead.
     */
    public static function isConfirmedDeadChat(Response $response): bool
    {
        return in_array($response->status(), [400, 403], true);
    }
}
