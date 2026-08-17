<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\SocialAccount\ToggleSocialAccount;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SocialController extends Controller
{
    protected SocialPlatform $platform;

    protected function ensurePlatformEnabled(): void
    {
        if (isset($this->platform) && ! $this->platform->isEnabled()) {
            abort(SymfonyResponse::HTTP_FORBIDDEN, 'This platform is currently unavailable.');
        }
    }

    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return Inertia::render('accounts/Index', [
            'workspace' => $workspace,
            'platforms' => SocialPlatform::connectableOptions(),
            'connectedAccounts' => SocialAccountResource::collection(
                $workspace->socialAccounts()->orderBy('id')->get(),
            )->resolve(),
        ]);
    }

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        if ($account->workspace_id !== $workspace->id) {
            abort(403);
        }

        // Drop pending platform rows from drafts/scheduled posts so the account
        // disappears cleanly from their UI. Published/failed rows survive via the
        // FK's nullOnDelete cascade and keep their snapshot fields for history.
        $account->postPlatforms()
            ->where('status', PostPlatformStatus::Pending->value)
            ->delete();

        $account->delete();

        session()->flash('flash.banner', __('accounts.flash.disconnected'));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    public function toggleActive(Request $request, SocialAccount $account): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        if ($account->workspace_id !== $workspace->id) {
            abort(403);
        }

        ToggleSocialAccount::execute($account);

        $status = $account->is_active ? 'activated' : 'deactivated';
        session()->flash('flash.banner', __("accounts.flash.{$status}"));
        session()->flash('flash.bannerStyle', 'success');

        return back();
    }

    protected function redirectToProvider(Request $request, string $driver, array $scopes): SymfonyResponse
    {
        $workspace = $request->user()->currentWorkspace;

        session(['social_connect_workspace' => $workspace->id]);

        if ($brokerUrl = $this->brokerUrl()) {
            return Inertia::location($this->brokerStartUrl($brokerUrl, $driver, $workspace->id));
        }

        return Inertia::location(
            Socialite::driver($driver)
                ->scopes($scopes)
                ->redirect()
                ->getTargetUrl()
        );
    }

    protected function brokerUrl(): ?string
    {
        return config('trypost.oauth_broker_url') ?: null;
    }

    /**
     * `$platform` matches the broker's own PLATFORMS key (app/oauth_broker.py
     * in storia-hosted-apps), not necessarily this app's driver/platform enum
     * name - callers pass whichever key the broker expects.
     */
    protected function brokerStartUrl(string $brokerUrl, string $platform, string $workspaceId): string
    {
        return rtrim($brokerUrl, '/')."/oauth/start/{$platform}?".http_build_query([
            'client' => request()->getHost(),
            'workspace' => $workspaceId,
        ]);
    }

    /**
     * Verifies and decodes the signed handoff payload the broker redirects
     * back with after it completes the token exchange server-side. Returns
     * null on any failure (bad signature, expired, malformed) - callers treat
     * that identically to "no payload" and fall back to popupCallback(false).
     *
     * @return array<string, mixed>|null
     */
    protected function resolveBrokerPayload(Request $request): ?array
    {
        $secret = config('trypost.oauth_broker_handoff_secret');
        $raw = $request->query('payload');

        if (! $secret || ! is_string($raw) || ! str_contains($raw, '.')) {
            return null;
        }

        [$payloadB64, $signature] = explode('.', $raw, 2);
        $expected = hash_hmac('sha256', $payloadB64, $secret);

        if (! hash_equals($expected, $signature)) {
            Log::warning('OAuth broker handoff signature mismatch');

            return null;
        }

        // The broker (Python) strips base64url padding before signing; PHP's
        // decoder requires it back to accept the string in strict mode.
        $padded = $payloadB64.str_repeat('=', (4 - strlen($payloadB64) % 4) % 4);
        $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
        $payload = $decoded ? json_decode($decoded, true) : null;

        if (! is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    protected function handleCallback(
        Request $request,
        SocialPlatform $platform,
        string $driver
    ): Response {
        $workspaceId = session('social_connect_workspace');

        if (! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $platform->value);
        }

        try {
            $socialUser = Socialite::driver($driver)->user();

            $avatarPath = uploadFromUrl($socialUser->getAvatar());

            $workspace->socialAccounts()->updateOrCreate(
                [
                    'platform' => $platform->value,
                    'platform_user_id' => $socialUser->getId(),
                ],
                [
                    'username' => $socialUser->getNickname(),
                    'display_name' => $socialUser->getName(),
                    'avatar_url' => $avatarPath,
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                    'scopes' => $socialUser->approvedScopes ?? null,
                    'status' => Status::Connected,
                    'error_message' => null,
                    'disconnected_at' => null,
                ],
            );

            return $this->popupCallback(true, __('accounts.popup_callback.connected'), $platform->value);
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $platform->value);
        } catch (\Exception $e) {
            Log::error('Social OAuth Error', [
                'platform' => $platform->value,
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $platform->value);
        }
    }

    protected function forgetSocialConnectSession(): void
    {
        session()->forget('social_connect_workspace');
    }

    /**
     * Render the Inertia page that notifies the opener and closes the connect
     * popup. Used by both the GET OAuth callbacks (a fresh popup page load) and
     * the XHR selection submits (an Inertia visit that swaps to this page).
     *
     * Always pass `onboardingProgress` as inline false so it overrides the shared
     * deferred prop: after select the URL is still the select path, and a deferred
     * reload would re-GET that route with a cleared session.
     */
    protected function popupCallback(bool $success, string $message, ?string $platform = null): Response
    {
        $this->forgetSocialConnectSession();

        return Inertia::render('accounts/PopupCallback', [
            'success' => $success,
            'message' => $message,
            'platform' => $platform,
            'onboardingProgress' => false,
        ]);
    }
}
