<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Normalizes the failure modes of an OAuth token-refresh request:
 *
 * - ConnectionException (timeout / DNS / refused) → PlatformUnavailableException
 * - HTTP 5xx or 429                               → PlatformUnavailableException
 * - other HTTP 4xx                                → TokenExpiredException
 *
 * Providers that return rate-limit or transient errors as an ordinary 4xx
 * (Meta: Instagram/Threads) can pass an `$isTokenInvalid` classifier to
 * `send()`; only a genuinely dead token then disconnects, while every other
 * 4xx is treated as transient (PlatformUnavailableException).
 *
 * Callers configure the actual HTTP call through the closure passed to
 * `send()`, so platform-specific quirks (form vs JSON body, auth headers,
 * basic auth, etc.) stay where they belong — in the per-platform refresh
 * method.
 */
class TokenRefreshClient
{
    public function __construct(public readonly Platform $platform) {}

    public static function for(Platform $platform): self
    {
        return new self($platform);
    }

    /**
     * @param  Closure():Response  $request
     * @param  (Closure(array<string, mixed>|null):bool)|null  $isTokenInvalid  Given the parsed
     *                                                                          response body, returns whether a non-5xx/429 failure means the token itself is dead.
     *                                                                          When it returns false, the failure is treated as transient (PlatformUnavailableException).
     *
     * @throws PlatformUnavailableException
     * @throws TokenExpiredException
     */
    public function send(Closure $request, ?Closure $isTokenInvalid = null): Response
    {
        $name = $this->platform->label();

        try {
            $response = $request();
        } catch (ConnectionException $e) {
            throw new PlatformUnavailableException("{$name} API unreachable: {$e->getMessage()}");
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new PlatformUnavailableException(
                "{$name} API returned {$response->status()} during token refresh",
                $response->status(),
            );
        }

        if ($response->failed()) {
            Log::error("TokenRefreshClient: {$name} token refresh failed", [
                'body' => TokenRedactor::redact($response->body()),
            ]);

            $body = $response->json();

            if ($isTokenInvalid !== null && ! $isTokenInvalid($body)) {
                throw new PlatformUnavailableException(
                    "{$name} API returned {$response->status()} during token refresh",
                    $response->status(),
                );
            }

            $message = data_get($body, 'error_description')
                ?? data_get($body, 'error.message')
                ?? "Failed to refresh {$name} token";

            throw new TokenExpiredException($message, platformErrorCode: (string) $response->status());
        }

        return $response;
    }
}
