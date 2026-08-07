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

        return Inertia::location(
            Socialite::driver($driver)
                ->scopes($scopes)
                ->redirect()
                ->getTargetUrl()
        );
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
     */
    protected function popupCallback(bool $success, string $message, ?string $platform = null): Response
    {
        $this->forgetSocialConnectSession();

        return Inertia::render('accounts/PopupCallback', [
            'success' => $success,
            'message' => $message,
            'platform' => $platform,
        ]);
    }
}
