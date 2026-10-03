<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;

class AnalyticsAccessToken
{
    /**
     * The account's access token, refreshed first when it has expired, so a
     * collector never spends a request on a token the provider will reject.
     */
    public static function for(SocialAccount $account): string
    {
        if ($account->needsProactiveTokenRefresh()) {
            try {
                app(ConnectionVerifier::class)->refreshToken($account);
            } catch (TokenExpiredException $exception) {
                throw new AnalyticsCollectionException('authentication', $exception->getMessage());
            } catch (PlatformUnavailableException $exception) {
                throw new AnalyticsCollectionException('transient', $exception->getMessage());
            }
        }

        return (string) $account->access_token;
    }
}
