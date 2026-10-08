<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Exceptions\SocialAccount\ConnectFlowException;
use App\Models\SocialAccount;
use App\Services\Social\BlueskyLexicon;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

class BlueskyController extends SocialController
{
    protected SocialPlatform $platform = SocialPlatform::Bluesky;

    public function connect(Request $request): InertiaResponse
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        return Inertia::render('accounts/BlueskyConnect', [
            'errors' => session('errors')?->getBag('default')?->toArray() ?? [],
            'backUrl' => PendingConnection::current()?->returnUrl() ?? PendingConnection::defaultReturnUrl(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensurePlatformEnabled();

        $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string|min:3',
        ]);

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $service = config('trypost.platforms.bluesky.default_service');

        if (PendingConnection::current()?->platform() !== $this->platform) {
            $this->rememberConnectSession($request, $workspace);
        }

        try {
            // Authenticate with Bluesky
            $response = Http::post("{$service}/xrpc/".BlueskyLexicon::CREATE_SESSION, [
                'identifier' => $request->identifier,
                'password' => $request->password,
            ]);

            if ($response->failed()) {
                Log::error('Bluesky authentication failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                throw ValidationException::withMessages(['password' => __('accounts.bluesky.invalid_credentials')]);
            }

            $data = $response->json();

            if (data_get($data, 'emailConfirmed') !== true) {
                return $this->failConnection(data_get($data, 'emailConfirmed') === false
                    ? 'bluesky_email_unconfirmed'
                    : 'error_connecting');
            }

            // Get profile
            $profileResponse = Http::withToken(data_get($data, 'accessJwt'))
                ->get("{$service}/xrpc/".BlueskyLexicon::GET_PROFILE, [
                    'actor' => data_get($data, 'did'),
                ]);

            $profile = $profileResponse->successful() ? $profileResponse->json() : [];

            return $this->offerIdentities($workspace, [
                PendingConnection::identity(
                    $this->platform,
                    (string) data_get($data, 'did'),
                    data_get($profile, 'displayName') ?: data_get($data, 'handle'),
                    data_get($data, 'handle'),
                    data_get($profile, 'avatar'),
                    $this->platform->identityType()->value,
                    [
                        'username' => data_get($data, 'handle'),
                        'display_name' => data_get($profile, 'displayName', data_get($data, 'handle')),
                        'access_token' => data_get($data, 'accessJwt'),
                        'refresh_token' => data_get($data, 'refreshJwt'),
                        'token_expires_at' => now()->addHours(2),
                        'meta' => [
                            'service' => $service,
                            'identifier' => $request->identifier,
                            'password' => encrypt($request->password),
                        ],
                    ],
                ),
            ], $this->reconnectAccount($workspace));
        } catch (ValidationException $e) {
            throw $e;
        } catch (Exception $e) {
            Log::error('Bluesky connection error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw ValidationException::withMessages(['password' => __('accounts.bluesky.connection_error')]);
        }
    }

    protected function accountValues(array $identity, ?SocialAccount $reconnect): array
    {
        $response = Http::withToken(data_get($identity, 'attributes.access_token'))
            ->connectTimeout(10)
            ->timeout(30)
            ->get(config('trypost.platforms.bluesky.default_service').'/xrpc/'.BlueskyLexicon::GET_SESSION);

        if (! $response->successful() || $response->json('did') !== data_get($identity, 'platform_user_id')) {
            throw new ConnectFlowException('error_connecting', $this->platform);
        }

        if ($response->json('emailConfirmed') !== true) {
            throw new ConnectFlowException($response->json('emailConfirmed') === false
                ? 'bluesky_email_unconfirmed'
                : 'error_connecting', $this->platform);
        }

        return parent::accountValues($identity, $reconnect);
    }
}
