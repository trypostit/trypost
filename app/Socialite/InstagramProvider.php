<?php

declare(strict_types=1);

namespace App\Socialite;

use App\Support\OAuthScopes;
use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;

class InstagramProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopes = [
        'instagram_business_basic',
        'instagram_business_content_publish',
    ];

    protected function getAuthUrl($state): string
    {
        // enable_fb_login=0 forces the pure Instagram Login flow (without
        // delegating to Facebook OAuth). Tokens issued from the FB-delegated
        // path can't be exchanged via graph.instagram.com/access_token.
        return config('trypost.platforms.instagram.oauth_url').'/oauth/authorize?enable_fb_login=0&'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'response_type' => 'code',
            'scope' => OAuthScopes::commaSeparated($this->getScopes()),
            'state' => $state,
            'force_reauth' => 'true',
        ]);
    }

    protected function getTokenUrl(): string
    {
        return config('trypost.platforms.instagram.oauth_api').'/oauth/access_token';
    }

    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get(config('trypost.platforms.instagram.graph_api').'/me', [
            RequestOptions::QUERY => [
                'access_token' => $token,
                'fields' => 'id,user_id,username,account_type,name,profile_picture_url',
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => $user['id'],
            'nickname' => $user['username'] ?? null,
            'name' => $user['name'] ?? $user['username'] ?? null,
            'avatar' => $user['profile_picture_url'] ?? null,
        ]);
    }

    public function getAccessTokenResponse($code): array
    {
        // Meta's docs document this endpoint with curl -F flags (multipart/form-data).
        $multipart = [];
        foreach ($this->getTokenFields($code) as $name => $contents) {
            $multipart[] = ['name' => $name, 'contents' => (string) $contents];
        }

        $response = $this->getHttpClient()->post($this->getTokenUrl(), [
            RequestOptions::MULTIPART => $multipart,
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $data = data_get($data, 'data.0', $data);

        return $this->exchangeForLongLivedToken([
            ...$data,
            'scope' => OAuthScopes::commaSeparated((array) data_get($data, 'permissions', [])),
        ]);
    }

    protected function exchangeForLongLivedToken(array $data): array
    {
        // Although Meta's docs don't list `client_id` as a required parameter,
        // the API in practice rejects the request without it.
        $response = $this->getHttpClient()->get(config('trypost.platforms.instagram.auth_api').'/access_token', [
            RequestOptions::QUERY => [
                'grant_type' => 'ig_exchange_token',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'access_token' => data_get($data, 'access_token'),
            ],
        ]);

        $longLivedData = json_decode((string) $response->getBody(), true);

        return array_merge($data, [
            'access_token' => $longLivedData['access_token'],
            'expires_in' => $longLivedData['expires_in'] ?? null,
        ]);
    }

    protected function getTokenFields($code): array
    {
        return [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUrl,
            'code' => $code,
        ];
    }
}
