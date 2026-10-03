<?php

declare(strict_types=1);

namespace App\Services\Media\Sources;

use App\Enums\Media\Source;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Google's OAuth web-server flow for the composer's Drive and Photos
 * sources: one narrow scope per source, PKCE (S256), an online token only.
 * Granted scopes are not folded in, so the Drive token that reaches the
 * browser for the Picker carries `drive.file` and nothing else.
 * The redirect URI is the one registered with Google, read from config.
 */
class GoogleMediaOAuth
{
    public const array SCOPES = [
        'google_drive' => 'https://www.googleapis.com/auth/drive.file',
        'google_photos' => 'https://www.googleapis.com/auth/photospicker.mediaitems.readonly',
    ];

    private const int REQUEST_TIMEOUT_SECONDS = 20;

    public static function scope(Source $source): string
    {
        return self::SCOPES[$source->value] ?? throw new InvalidArgumentException("{$source->value} has no Google scope.");
    }

    public function authorizeUrl(Source $source, string $state, string $codeChallenge): string
    {
        $query = http_build_query([
            'client_id' => (string) config('services.google_media.client_id'),
            'redirect_uri' => (string) config('services.google_media.redirect'),
            'response_type' => 'code',
            'scope' => self::scope($source),
            'access_type' => 'online',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ], encoding_type: PHP_QUERY_RFC3986);

        return config('trypost.media_sources.google_oauth.authorize_url')."?{$query}";
    }

    /**
     * @return array{access_token: string, expires_in: int, scopes: list<string>}
     */
    public function exchangeCode(string $code, string $verifier): array
    {
        $tokenApi = rtrim((string) config('trypost.media_sources.google_oauth.token_api'), '/');

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(self::REQUEST_TIMEOUT_SECONDS)
            ->post("{$tokenApi}/token", [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'code_verifier' => $verifier,
                'client_id' => (string) config('services.google_media.client_id'),
                'client_secret' => (string) config('services.google_media.client_secret'),
                'redirect_uri' => (string) config('services.google_media.redirect'),
            ]);

        $accessToken = (string) $response->json('access_token', '');

        if (! $response->successful() || $accessToken === '') {
            throw new RuntimeException("Google code exchange failed ({$response->status()}).");
        }

        return [
            'access_token' => $accessToken,
            'expires_in' => (int) $response->json('expires_in', 0),
            'scopes' => array_values(array_filter(explode(' ', (string) $response->json('scope', '')))),
        ];
    }
}
