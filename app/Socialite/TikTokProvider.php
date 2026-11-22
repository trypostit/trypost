<?php

declare(strict_types=1);

namespace App\Socialite;

use Illuminate\Support\Str;
use SocialiteProviders\TikTok\Provider;

class TikTokProvider extends Provider
{
    /**
     * Use the granted scopes before the parent driver chooses which profile fields to request.
     *
     * @param  string  $code  The authorization code passed by Socialite.
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse(mixed $code): array
    {
        $tokenResponse = parent::getAccessTokenResponse($code);
        $reportedScopes = data_get($tokenResponse, 'scope');

        if (blank($reportedScopes)) {
            return $tokenResponse;
        }

        $this->setScopes(Str::matchAll('/[^\s,]+/', $reportedScopes)->all());

        return $tokenResponse;
    }
}
