<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Exceptions\SocialAccount\ConnectFlowException;
use App\Services\Social\TokenRedactor;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ThreadsController extends SocialController
{
    protected SocialPlatform $platform = SocialPlatform::Threads;

    protected array $scopes = [
        'threads_basic',
        'threads_content_publish',
        'threads_manage_insights',
    ];

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        $state = bin2hex(random_bytes(16));
        session(['threads_oauth_state' => $state]);

        $params = http_build_query([
            'client_id' => config('services.threads.client_id'),
            'redirect_uri' => config('services.threads.redirect'),
            'scope' => implode(',', $this->requestedScopes()),
            'response_type' => 'code',
            'state' => $state,
        ]);

        return Inertia::location(config('trypost.platforms.threads.oauth_url')."/oauth/authorize?{$params}");
    }

    public function callback(Request $request): RedirectResponse
    {
        $savedState = session('threads_oauth_state');
        session()->forget('threads_oauth_state');
        $workspace = $this->connectWorkspace($request);

        if ($request->state !== $savedState) {
            throw new ConnectFlowException('invalid_state', $this->platform);
        }

        try {
            // Exchange code for short-lived token
            $tokenResponse = Http::asForm()->post(config('trypost.platforms.threads.auth_api').'/oauth/access_token', [
                'client_id' => config('services.threads.client_id'),
                'client_secret' => config('services.threads.client_secret'),
                'grant_type' => 'authorization_code',
                'redirect_uri' => config('services.threads.redirect'),
                'code' => $request->code,
            ]);

            if ($tokenResponse->failed()) {
                Log::error('Threads token exchange failed', [
                    'status' => $tokenResponse->status(),
                    'body' => TokenRedactor::redact($tokenResponse->body()),
                ]);
                throw new Exception('Failed to exchange token');
            }

            $tokenData = $tokenResponse->json();
            $shortLivedToken = $tokenData['access_token'];
            $userId = $tokenData['user_id'];

            // Exchange for long-lived token
            $longLivedResponse = Http::get(config('trypost.platforms.threads.auth_api').'/access_token', [
                'grant_type' => 'th_exchange_token',
                'client_secret' => config('services.threads.client_secret'),
                'access_token' => $shortLivedToken,
            ]);

            if ($longLivedResponse->failed()) {
                Log::error('Threads long-lived token exchange failed', [
                    'status' => $longLivedResponse->status(),
                    'body' => TokenRedactor::redact($longLivedResponse->body()),
                ]);
                throw new Exception('Failed to exchange long-lived token');
            }

            $longLivedData = $longLivedResponse->json();
            $longLivedToken = $longLivedData['access_token'] ?? $shortLivedToken;
            $expiresIn = $longLivedData['expires_in'] ?? $this->platform->defaultTokenTtlSeconds();
            $scopes = $this->tokenScopes($longLivedToken);
            $refusal = $this->refusalForMissingPublishScopes($scopes);

            if ($refusal !== null) {
                return $refusal;
            }

            $profileResponse = Http::get(config('trypost.platforms.threads.graph_api')."/{$userId}", [
                'access_token' => $longLivedToken,
                'fields' => 'id,username,name,threads_profile_picture_url',
            ]);

            if ($profileResponse->failed()) {
                Log::error('Threads profile fetch failed', [
                    'body' => $profileResponse->body(),
                ]);
                throw new Exception('Failed to fetch profile');
            }

            $profile = $profileResponse->json();

            return $this->offerIdentities($workspace, [
                PendingConnection::identity(
                    $this->platform,
                    (string) data_get($profile, 'id'),
                    data_get($profile, 'name', data_get($profile, 'username')),
                    data_get($profile, 'username'),
                    data_get($profile, 'threads_profile_picture_url'),
                    $this->platform->identityType()->value,
                    [
                        'username' => data_get($profile, 'username'),
                        'display_name' => data_get($profile, 'name', data_get($profile, 'username')),
                        'access_token' => $longLivedToken,
                        'refresh_token' => null,
                        'token_expires_at' => now()->addSeconds($expiresIn),
                        'scopes' => $scopes,
                    ],
                ),
            ], $this->reconnectAccount($workspace));
        } catch (Exception $e) {
            Log::error('Threads OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }

    /**
     * The scopes Threads reports for this token through `/debug_token`. The token
     * response carries none, and a failed lookup leaves the requested list.
     *
     * @return array<int, string>
     */
    private function tokenScopes(string $accessToken): array
    {
        $response = rescue(fn () => Http::timeout(15)->connectTimeout(5)->get(config('trypost.platforms.threads.graph_api').'/debug_token', [
            'access_token' => $accessToken,
            'input_token' => $accessToken,
        ]), report: false);

        return $this->reportedScopes(
            $response?->successful() ? $response->json('data.scopes') : null,
            $this->requestedScopes(),
        );
    }
}
