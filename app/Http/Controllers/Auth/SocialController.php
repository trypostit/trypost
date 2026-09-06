<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\SocialAccount\ToggleSocialAccount;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\Repurpose\Status as RepurposeStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\ConnectPopupException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Http\Controllers\Controller;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SocialController extends Controller
{
    protected SocialPlatform $platform;

    /** The platform's API host, keyed in config by the enum value. */
    protected function graphApi(): string
    {
        return (string) config("trypost.platforms.{$this->platform->value}.graph_api");
    }

    protected function ensurePlatformEnabled(): void
    {
        if (! $this->platform->isEnabled()) {
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

        $before = $this->repurposeStatesFor($account);

        $account->delete();

        $this->flashAccountChange('disconnected', $before);

        return back();
    }

    public function toggleActive(Request $request, SocialAccount $account): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        if ($account->workspace_id !== $workspace->id) {
            abort(403);
        }

        // Captured regardless of direction, and never from $account->is_active:
        // reading a column off the instance is what silently broke isUsable().
        $before = $this->repurposeStatesFor($account);

        ToggleSocialAccount::execute($account);

        $this->flashAccountChange($account->is_active ? 'activated' : 'deactivated', $before);

        return back();
    }

    /**
     * The workspace the connect popup was opened for.
     *
     * @throws ConnectPopupException when the session is gone or the user may no
     *                               longer manage the workspace's accounts.
     */
    protected function connectWorkspace(Request $request): Workspace
    {
        $workspaceId = session('social_connect_workspace');

        if (! $workspaceId) {
            throw new ConnectPopupException('session_expired', $this->platform);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            throw new ConnectPopupException('workspace_not_found', $this->platform);
        }

        return $workspace;
    }

    protected function rememberConnectSession(Request $request, Workspace $workspace): void
    {
        session([
            'social_connect_workspace' => $workspace->id,
            'social_reconnect_id' => $this->validatedReconnectId($request, $workspace),
        ]);
    }

    /**
     * The empty-string default keeps a missing query param from falling through
     * to the session, which would let one network's reconnect leak into another.
     */
    protected function validatedReconnectId(Request $request, Workspace $workspace): ?string
    {
        return $this->reconnectAccount($workspace, $request->query('reconnect', ''))?->id;
    }

    protected function reconnectAccount(Workspace $workspace, mixed $reconnectId = null): ?SocialAccount
    {
        $reconnectId ??= session('social_reconnect_id');

        if (! is_string($reconnectId) || $reconnectId === '') {
            return null;
        }

        return $workspace->socialAccounts()
            ->whereIn('platform', $this->platform->networkPlatformValues())
            ->find($reconnectId);
    }

    /**
     * Nothing on this network is left to connect: the card being reconnected is
     * gone from the provider, this login has nothing left to offer, or the
     * single slot is taken.
     *
     * A taken slot is a fact about our own rows, so it stands even when the provider
     * listing came back short. The other two answers depend on having seen everything.
     */
    protected function noConnectableIdentities(?SocialAccount $reconnect, string $missingKey, bool $listingComplete = true): Response
    {
        $key = match (true) {
            ! (bool) config('trypost.allow_multiple_social_accounts') && $reconnect === null => 'network_taken',
            $listingComplete => $reconnect !== null ? $missingKey : 'all_connected',
            default => 'pages_read_incomplete',
        };

        return $this->popupCallback(false, __("accounts.popup_callback.{$key}"), $this->platform->value);
    }

    /**
     * Narrow the identities a provider returned to the ones this card may take.
     *
     * A reconnect only ever offers its own identity. Otherwise every identity
     * already connected on this network is dropped — including in multi-account
     * mode, where the same identity could otherwise be connected twice under two
     * platforms of one network (Instagram directly and via Facebook).
     *
     * @param  array<int, array<string, mixed>>  $identities
     * @return array<int, array<string, mixed>>
     */
    protected function filterConnectableIdentities(
        Workspace $workspace,
        array $identities,
        string $idKey,
        ?SocialAccount $reconnect = null,
    ): array {
        $byId = collect($identities)->keyBy(fn (array $identity) => (string) data_get($identity, $idKey));
        $reconnect ??= $this->reconnectAccount($workspace);

        if ($reconnect) {
            return $byId->only([(string) $reconnect->platform_user_id])->values()->all();
        }

        return $byId->except(
            $workspace->socialAccounts()
                ->whereIn('platform', $this->platform->networkPlatformValues())
                ->pluck('platform_user_id')
                ->map(strval(...)),
        )->values()->all();
    }

    protected function redirectToProvider(Request $request, string $driver, array $scopes): SymfonyResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->rememberConnectSession($request, $workspace);

        return Inertia::location(
            Socialite::driver($driver)
                ->scopes($scopes)
                ->redirect()
                ->getTargetUrl()
        );
    }

    protected function handleCallback(Request $request, string $driver): Response
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($driver)->user();
            $reconnect = $this->reconnectAccount($workspace);

            $avatarPath = uploadFromUrl($socialUser->getAvatar());

            SocialAccount::connectIdentity(
                $workspace,
                $this->platform,
                $socialUser->getId(),
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
                $reconnect,
            );

            return $this->connectedCallback($reconnect);
        } catch (NetworkAlreadyConnectedException $e) {
            return $this->popupCallback(false, __("accounts.popup_callback.{$e->messageKey}"), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Social OAuth Error', [
                'platform' => $this->platform->value,
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    /**
     * Close the popup on a successful connect, wording it as a reconnect when
     * the flow updated an existing card.
     */
    protected function connectedCallback(?SocialAccount $reconnect): Response
    {
        return $this->popupCallback(true, $reconnect
            ? __('accounts.popup_callback.reconnected')
            : __('accounts.popup_callback.connected'), $this->platform->value);
    }

    protected function forgetSocialConnectSession(): void
    {
        session()->forget(['social_connect_workspace', 'social_reconnect_id']);
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

    /**
     * Status per repurpose, captured before the account changes. Taken before
     * the delete because the source FK is nullOnDelete, and used as the
     * baseline for what the observer went on to change.
     *
     * @return Collection<string, RepurposeStatus>
     */
    private function repurposeStatesFor(SocialAccount $account): Collection
    {
        return Repurpose::query()
            ->where('source_social_account_id', $account->id)
            ->pluck('status', 'id');
    }

    /**
     * The observer has already done whatever it was going to do by now, so this
     * compares before and after rather than predicting either.
     *
     * With no email in this flow — the user did this deliberately, so an email
     * would be noise — the flash is the only notice that an automation stopped
     * or started, and it happens on the accounts page rather than where the
     * repurpose lives.
     *
     * @param  Collection<string, RepurposeStatus>  $before
     */
    private function flashAccountChange(string $action, Collection $before): void
    {
        $after = Repurpose::query()->whereKey($before->keys())->pluck('status', 'id');

        $paused = $before
            ->filter(fn (RepurposeStatus $status, string $id): bool => $status !== RepurposeStatus::Paused
                && $after->get($id) === RepurposeStatus::Paused)
            ->count();

        $resumed = $before
            ->filter(fn (RepurposeStatus $status, string $id): bool => $status === RepurposeStatus::Paused
                && $after->get($id) === RepurposeStatus::Active)
            ->count();

        session()->flash('flash.banner', match (true) {
            $paused > 0 => trans_choice("accounts.flash.{$action}_paused_repurposes", $paused, ['count' => $paused]),
            $resumed > 0 => trans_choice("accounts.flash.{$action}_resumed_repurposes", $resumed, ['count' => $resumed]),
            default => __("accounts.flash.{$action}"),
        });
        session()->flash('flash.bannerStyle', 'success');
    }
}
