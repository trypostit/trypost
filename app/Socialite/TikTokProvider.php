<?php

declare(strict_types=1);

namespace App\Socialite;

use Illuminate\Support\Str;
use SocialiteProviders\TikTok\Provider;

class TikTokProvider extends Provider
{
    /**
     * The driver chooses profile fields immediately after the token exchange.
     * Use the grant TikTok returned, retaining the requested scopes if it was omitted.
     *
     * @param  string  $code  The authorization code passed by Socialite.
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse(mixed $code): array
    {
        $response = parent::getAccessTokenResponse($code);
        $scope = data_get($response, 'scope');

        if (filled($scope)) {
            $this->setScopes(Str::matchAll('/[^\s,]+/', $scope)->all());
        }

        return $response;
    }
}
