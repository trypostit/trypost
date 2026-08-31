<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\SocialAccount;
use App\Services\Social\BlueskyLexicon;
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
        ]);
    }

    public function store(Request $request): InertiaResponse
    {
        $this->ensurePlatformEnabled();

        $request->validate([
            'identifier' => 'required|string',
            'password' => 'required|string|min:3',
        ]);

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $service = config('trypost.platforms.bluesky.default_service');

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

                $errorMessage = 'Invalid credentials';
                $body = $response->json();
                if (isset($body['message'])) {
                    $errorMessage = $body['message'];
                }

                throw ValidationException::withMessages(['password' => $errorMessage]);
            }

            $data = $response->json();

            // Get profile
            $profileResponse = Http::withToken(data_get($data, 'accessJwt'))
                ->get("{$service}/xrpc/".BlueskyLexicon::GET_PROFILE, [
                    'actor' => data_get($data, 'did'),
                ]);

            $profile = $profileResponse->successful() ? $profileResponse->json() : [];

            $avatarPath = data_get($profile, 'avatar') ? uploadFromUrl(data_get($profile, 'avatar')) : null;
            $reconnect = $this->reconnectAccount($workspace);

            SocialAccount::connectIdentity(
                $workspace,
                $this->platform,
                (string) data_get($data, 'did'),
                [
                    'username' => data_get($data, 'handle'),
                    'display_name' => data_get($profile, 'displayName', data_get($data, 'handle')),
                    'avatar_url' => $avatarPath,
                    'access_token' => data_get($data, 'accessJwt'),
                    'refresh_token' => data_get($data, 'refreshJwt'),
                    'token_expires_at' => now()->addHours(2),
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                    'meta' => [
                        'service' => $service,
                        'identifier' => $request->identifier,
                        'password' => encrypt($request->password),
                    ],
                ],
                $reconnect,
            );

            return $this->connectedCallback($reconnect);
        } catch (ValidationException $e) {
            throw $e;
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Bluesky connection error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw ValidationException::withMessages(['password' => 'Error connecting to Bluesky. Please try again.']);
        }
    }
}
