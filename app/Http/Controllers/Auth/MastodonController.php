<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Exceptions\SocialAccount\ConnectFlowException;
use App\Services\Http\SafeHttpFetcher;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class MastodonController extends SocialController
{
    protected SocialPlatform $platform = SocialPlatform::Mastodon;

    /** @var list<string> */
    protected array $scopes = ['read:accounts', 'read:statuses', 'write:statuses', 'write:media'];

    /**
     * The instance step, before the redirect to the instance's consent screen.
     */
    public function connect(Request $request): InertiaResponse
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $this->rememberConnectSession($request, $workspace);

        return Inertia::render('accounts/MastodonConnect', [
            'errors' => session('errors')?->getBag('default')?->toArray() ?? [],
            'backUrl' => PendingConnection::current()?->returnUrl() ?? PendingConnection::defaultReturnUrl(),
        ]);
    }

    /**
     * Register app on instance and redirect to OAuth
     */
    public function authorizeInstance(Request $request, SafeHttpFetcher $fetcher): Response
    {
        $this->ensurePlatformEnabled();

        $request->validate([
            'instance' => 'required|url',
        ]);

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        $instance = rtrim($request->instance, '/');

        try {
            $fetcher->guardAgainstSsrf($instance);
        } catch (RuntimeException) {
            return back()->withErrors(['instance' => __('accounts.mastodon.instance_unreachable')]);
        }

        try {
            // Register app on the instance
            $appResponse = Http::post("{$instance}/api/v1/apps", [
                'client_name' => config('app.name'),
                'redirect_uris' => route('app.social.mastodon.callback'),
                'scopes' => $this->scopes(),
                'website' => config('app.url'),
            ]);

            if ($appResponse->failed()) {
                Log::error('Mastodon app registration failed', [
                    'instance' => $instance,
                    'status' => $appResponse->status(),
                    'body' => $appResponse->body(),
                ]);

                return back()->withErrors(['instance' => __('accounts.mastodon.instance_unreachable')]);
            }

            $app = $appResponse->json();

            // Store in session for callback
            $state = bin2hex(random_bytes(16));
            session([
                'mastodon_instance' => $instance,
                'mastodon_client_id' => $app['client_id'],
                'mastodon_client_secret' => $app['client_secret'],
                'mastodon_oauth_state' => $state,
            ]);

            if (PendingConnection::current()?->platform() !== $this->platform) {
                $this->rememberConnectSession($request, $workspace);
            }

            // Redirect to OAuth
            $params = http_build_query([
                'client_id' => $app['client_id'],
                'response_type' => 'code',
                'redirect_uri' => route('app.social.mastodon.callback'),
                'scope' => $this->scopes(),
                'state' => $state,
                'force_login' => PendingConnection::current()?->isSwitchingAccount() ? 'true' : null,
            ]);

            return Inertia::location("{$instance}/oauth/authorize?{$params}");
        } catch (Exception $e) {
            Log::error('Mastodon connection error', [
                'instance' => $instance,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['instance' => __('accounts.mastodon.connection_error')]);
        }
    }

    /**
     * Handle the OAuth callback.
     *
     * Everything the flow needs is captured into locals before the session is
     * cleared, so every exit below is free of cleanup.
     */
    public function callback(Request $request): RedirectResponse
    {
        $savedState = session('mastodon_oauth_state');
        $instance = session('mastodon_instance');
        $clientId = session('mastodon_client_id');
        $clientSecret = session('mastodon_client_secret');

        if (! $instance) {
            $this->clearMastodonSession();

            throw new ConnectFlowException(ConnectFlowException::SESSION_EXPIRED, $this->platform);
        }

        $this->clearMastodonSession();
        $workspace = $this->connectWorkspace($request);

        if ($request->state !== $savedState) {
            throw new ConnectFlowException('invalid_state', $this->platform);
        }

        try {
            // Exchange code for token
            $tokenResponse = Http::asForm()->post("{$instance}/oauth/token", [
                'grant_type' => 'authorization_code',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => route('app.social.mastodon.callback'),
                'code' => $request->code,
            ]);

            if ($tokenResponse->failed()) {
                Log::error('Mastodon token exchange failed', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->body(),
                ]);

                return $this->failConnection('failed_to_authenticate');
            }

            $tokenData = $tokenResponse->json();
            $accessToken = data_get($tokenData, 'access_token');

            $profileResponse = Http::withToken($accessToken)
                ->get("{$instance}/api/v1/accounts/verify_credentials");

            if ($profileResponse->failed()) {
                return $this->failConnection('failed_to_get_profile');
            }

            $profile = $profileResponse->json();

            $grantedScopes = $this->reportedScopes(data_get($tokenData, 'scope'), $this->requestedScopes());

            return $this->refusalForMissingPublishScopes($grantedScopes) ?? $this->offerIdentities($workspace, [
                PendingConnection::identity(
                    $this->platform,
                    (string) data_get($profile, 'id'),
                    data_get($profile, 'display_name') ?: data_get($profile, 'username'),
                    data_get($profile, 'acct'),
                    data_get($profile, 'avatar'),
                    $this->platform->identityType()->value,
                    [
                        'username' => data_get($profile, 'acct'),
                        'display_name' => data_get($profile, 'display_name') ?: data_get($profile, 'username'),
                        'access_token' => $accessToken,
                        'refresh_token' => null,
                        'token_expires_at' => null,
                        'scopes' => $grantedScopes,
                        'meta' => [
                            'instance' => $instance,
                            'client_id' => $clientId,
                            'client_secret' => $clientSecret,
                        ],
                    ],
                ),
            ], $this->reconnectAccount($workspace));
        } catch (Exception $e) {
            Log::error('Mastodon callback error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }

    /**
     * Only the Mastodon-specific keys: the pending connection keeps the rest.
     */
    private function clearMastodonSession(): void
    {
        session()->forget([
            'mastodon_instance',
            'mastodon_client_id',
            'mastodon_client_secret',
            'mastodon_oauth_state',
        ]);
    }

    private function scopes(): string
    {
        return implode(' ', $this->requestedScopes());
    }
}
