<?php

declare(strict_types=1);

namespace App\Services\Affonso;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/**
 * Affonso REST API (https://docs.affonso.io/api/introduction), authenticated
 * with the program's secret key. Each endpoint is one public method built on
 * `request()`, which holds the host, the key and the timeout; the host comes
 * from config and the key is never logged.
 */
class AffonsoClient
{
    private const int REQUEST_TIMEOUT_SECONDS = 15;

    /**
     * Without a secret key the app makes no call to Affonso (self-hosted
     * installs, local development).
     */
    public function isConfigured(): bool
    {
        return filled(config('services.affonso.api_key'));
    }

    /**
     * Turns the click the pixel recorded into a lead of its affiliate
     * (https://docs.affonso.io/api/endpoint/signups/create). Idempotent per
     * click: a second call returns the lead already recorded.
     *
     * @throws RequestException when Affonso refuses or fails the call.
     */
    public function recordSignup(string $clickId, string $email, string $externalUserId): void
    {
        $this->request()
            ->post('/signups', [
                'click_id' => $clickId,
                'email' => $email,
                'external_user_id' => $externalUserId,
            ])
            ->throw();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('trypost.affonso.api'))
            ->withToken(config('services.affonso.api_key'))
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }
}
