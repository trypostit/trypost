<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Post\DeleteChannelPosts;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Repurpose\Status as RepurposeStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\Post\QueueBusyException;
use App\Exceptions\SocialAccount\ConnectFlowException;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\FinishSocialConnectionRequest;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Social\PendingConnection;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class SocialController extends Controller
{
    protected SocialPlatform $platform;

    private const array CANCELLED_CONSENT_ERRORS = ['access_denied', 'user_cancelled_login', 'user_cancelled_authorize'];

    private const string MISSING_PUBLISH_PERMISSION = 'publish_permission_missing';

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

    public function disconnect(Request $request, SocialAccount $account): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        if ($account->workspace_id !== $workspace->id) {
            abort(403);
        }

        $before = $this->repurposeStatesFor($account);

        try {
            ReflowChannelQueue::withLock([$account->id], fn () => DB::transaction(function () use ($account): void {
                DeleteChannelPosts::forAccount($account);

                $account->delete();
            }));
        } catch (QueueBusyException $exception) {
            return back()->with('flash.error', $exception->getMessage());
        }

        $this->flashAccountChange($before);

        return back();
    }

    /**
     * The confirmation page: the identities the login offered, or why there are none.
     */
    public function confirm(Request $request): Response
    {
        $pending = $this->pendingForThisNetwork();
        $workspace = $pending ? Workspace::find($pending->workspaceId()) : null;

        if ($pending?->isReady() && ! ($workspace && $request->user()->can('manageAccounts', $workspace))) {
            $pending->fail('workspace_not_found');
        }

        $state = $this->confirmationState($pending);

        return Inertia::render('accounts/ConnectFinish', [
            'platform' => $this->platform->value,
            'state' => $state,
            'reason' => $state === 'error' ? __("accounts.connect.errors.{$pending?->failure()}") : null,
            'reconnecting' => $pending?->reconnectId() !== null,
            'identities' => $state === 'select' ? $this->identityCards($pending) : [],
            'retryUrl' => $this->startUrl($pending),
            'switchUrl' => $state === 'select' ? $this->startUrl($pending, switchingAccount: true) : null,
            'backUrl' => $pending?->returnUrl() ?? PendingConnection::defaultReturnUrl(),
        ]);
    }

    /**
     * "Finish connection": store the identities the user picked, under the rights
     * they hold now, then go back to where the connection started.
     */
    public function finish(FinishSocialConnectionRequest $request): RedirectResponse
    {
        $pending = $this->pendingForThisNetwork();

        if (! $pending?->isReady()) {
            return redirect()->route('app.social.connect.show', $this->platform);
        }

        $this->ensurePlatformEnabled();

        $workspace = Workspace::find($pending->workspaceId());

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->failConnection('workspace_not_found');
        }

        $reconnect = $this->reconnectAccount($workspace);

        try {
            $accounts = $this->connectIdentities($workspace, $pending->selected($request->validated('identities')), $reconnect);
        } catch (ConnectFlowException|NetworkAlreadyConnectedException $e) {
            return $this->failConnection($e->messageKey);
        } catch (Exception $e) {
            Log::error('Social connect error', [
                'platform' => $this->platform->value,
                'error' => $e->getMessage(),
            ]);

            return $this->failConnection('error_connecting');
        }

        PendingConnection::forget();

        $created = $accounts->first(fn (SocialAccount $account): bool => $account->wasRecentlyCreated && $reconnect === null);
        $channel = $created ?? $accounts->first();

        Inertia::flash('connectedChannel', [
            'accountId' => $channel->id,
            'created' => $created !== null,
        ]);

        return redirect()->route('app.channels.publish', $channel);
    }

    /**
     * The workspace the connection was started for.
     *
     * @throws ConnectFlowException when the user cancelled on the network, the
     *                              session is gone, or the user may no longer
     *                              manage the workspace's accounts.
     */
    protected function connectWorkspace(Request $request): Workspace
    {
        if ($this->consentCancelled($request)) {
            throw new ConnectFlowException(ConnectFlowException::CANCELLED, $this->platform);
        }

        $pending = $this->pendingForThisNetwork();

        if (! $pending) {
            throw new ConnectFlowException(ConnectFlowException::SESSION_EXPIRED, $this->platform);
        }

        $workspace = Workspace::find($pending->workspaceId());

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            throw new ConnectFlowException('workspace_not_found', $this->platform);
        }

        return $workspace;
    }

    /**
     * Whether the network sent the user back because they cancelled its consent
     * screen: `access_denied` (RFC 6749 4.1.2.1, Google, Meta, TikTok, X, Pinterest,
     * Discord, Mastodon), Meta's `error_reason=user_denied`, and LinkedIn's
     * `user_cancelled_login` / `user_cancelled_authorize`.
     */
    protected function consentCancelled(Request $request): bool
    {
        return in_array($request->query('error'), self::CANCELLED_CONSENT_ERRORS, true)
            || $request->query('error_reason') === 'user_denied';
    }

    /**
     * The scopes the network reports this login granted. Networks join them with
     * spaces or commas; a network that reports none leaves the requested list,
     * since an unknown grant is not a refusal.
     *
     * @param  array<int, string>|string|null  $reported
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    protected function reportedScopes(array|string|null $reported, array $requested): array
    {
        $scopes = array_values(array_unique(array_filter(
            preg_split('/[\s,]+/', implode(' ', (array) $reported)) ?: [],
        )));

        return $scopes === [] ? array_values($requested) : $scopes;
    }

    /**
     * Refuses the connection when the login left out a scope the platform needs
     * to publish, so no account is stored that could only fail later.
     *
     * @param  array<int, string>  $granted
     */
    protected function refusalForMissingPublishScopes(array $granted): ?RedirectResponse
    {
        if (array_diff($this->platform->requiredPublishScopes(), $granted) === []) {
            return null;
        }

        return $this->failConnection(self::MISSING_PUBLISH_PERMISSION);
    }

    protected function rememberConnectSession(Request $request, Workspace $workspace): void
    {
        PendingConnection::start(
            $this->platform,
            $workspace,
            $this->validatedReconnectId($request, $workspace),
            $request->query('return_to'),
            $this->switchingAccount($request),
        );
    }

    /**
     * Whether the start route was opened from "Switch account" on the
     * confirmation page. Only the exact flag counts; any other value is ignored.
     */
    protected function switchingAccount(Request $request): bool
    {
        return $request->query('switch') === '1';
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
        $reconnectId ??= PendingConnection::current()?->reconnectId();

        if (! is_string($reconnectId) || $reconnectId === '') {
            return null;
        }

        return $workspace->socialAccounts()
            ->whereIn('platform', $this->platform->networkPlatformValues())
            ->find($reconnectId);
    }

    /**
     * Keeps the identities the confirmation page may offer and sends the user
     * there. A reconnect offers only the card being reconnected; otherwise an
     * identity already seated under another platform of the same network
     * (Instagram directly and via Facebook) is left out, while one already
     * connected on this platform stays, shown as connected.
     *
     * @param  list<array<string, mixed>>  $identities
     */
    protected function offerIdentities(
        Workspace $workspace,
        array $identities,
        ?SocialAccount $reconnect,
        string $missingKey = 'wrong_account',
        bool $listingComplete = true,
    ): RedirectResponse {
        $offered = $this->connectableIdentities($workspace, $identities, $reconnect);

        if ($offered === []) {
            return $this->failConnection(match (true) {
                ! $listingComplete => 'pages_read_incomplete',
                $reconnect !== null => $missingKey,
                default => 'all_connected',
            });
        }

        PendingConnection::current()?->offer($offered);

        return redirect()->route('app.social.connect.show', $this->platform);
    }

    /**
     * @param  list<array<string, mixed>>  $identities
     * @return list<array<string, mixed>>
     */
    protected function connectableIdentities(Workspace $workspace, array $identities, ?SocialAccount $reconnect): array
    {
        if ($reconnect !== null) {
            return array_values(array_filter(
                $identities,
                fn (array $identity): bool => data_get($identity, 'platform_user_id') === (string) $reconnect->platform_user_id,
            ));
        }

        $seated = $workspace->socialAccounts()
            ->whereIn('platform', $this->platform->networkPlatformValues())
            ->get(['platform', 'platform_user_id']);

        return array_values(array_filter(
            $identities,
            fn (array $identity): bool => ! $seated->contains(
                fn (SocialAccount $account): bool => (string) $account->platform_user_id === data_get($identity, 'platform_user_id')
                    && $account->platform->value !== data_get($identity, 'platform'),
            ),
        ));
    }

    /**
     * Sends the user to the confirmation page with the reason the connection
     * stopped. The tokens are dropped; where to return is kept for "Try again".
     */
    protected function failConnection(string $reason): RedirectResponse
    {
        $pending = PendingConnection::current();

        if ($pending?->platform() === $this->platform) {
            $pending->fail($reason);
        }

        return redirect()->route('app.social.connect.show', $this->platform);
    }

    /**
     * What the stored identity becomes on the account row, resolved before the
     * write: the avatar is copied to our storage here, outside the transaction.
     *
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    protected function accountValues(array $identity, ?SocialAccount $reconnect): array
    {
        $values = (array) data_get($identity, 'attributes', []);

        if (! data_get($identity, 'keeps_avatar')) {
            $values['avatar_url'] = uploadFromUrl(data_get($identity, 'avatar'));
        }

        return [
            ...$values,
            'status' => Status::Connected,
            'error_message' => null,
            'disconnected_at' => null,
        ];
    }

    /** Work a network needs once its account is stored, outside the transaction. */
    protected function afterConnected(SocialAccount $account): void {}

    protected function redirectToProvider(Request $request, string $driver, array $scopes, array $parameters = []): SymfonyResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->rememberConnectSession($request, $workspace);

        return Inertia::location(
            Socialite::driver($driver)
                ->scopes($scopes)
                ->with($parameters)
                ->redirect()
                ->getTargetUrl()
        );
    }

    /**
     * @param  array<int, string>  $requestedScopes
     */
    protected function handleCallback(Request $request, string $driver, array $requestedScopes): RedirectResponse
    {
        $workspace = $this->connectWorkspace($request);

        try {
            $socialUser = Socialite::driver($driver)->user();
            $scopes = $this->reportedScopes($socialUser->approvedScopes, $requestedScopes);

            return $this->refusalForMissingPublishScopes($scopes) ?? $this->offerIdentities($workspace, [
                PendingConnection::identity(
                    $this->platform,
                    (string) $socialUser->getId(),
                    $socialUser->getName() ?? $socialUser->getNickname(),
                    $socialUser->getNickname(),
                    $socialUser->getAvatar(),
                    $this->platform->identityType()->value,
                    [
                        'username' => $socialUser->getNickname(),
                        'display_name' => $socialUser->getName(),
                        'access_token' => $socialUser->token,
                        'refresh_token' => $socialUser->refreshToken,
                        'token_expires_at' => $socialUser->expiresIn ? now()->addSeconds($socialUser->expiresIn) : null,
                        'scopes' => $scopes,
                    ],
                ),
            ], $this->reconnectAccount($workspace));
        } catch (Exception $e) {
            Log::error('Social OAuth Error', [
                'platform' => $this->platform->value,
                'error' => $e->getMessage(),
            ]);

            return $this->failConnection('error_connecting');
        }
    }

    private function pendingForThisNetwork(): ?PendingConnection
    {
        $pending = PendingConnection::current();

        return $pending?->platform() === $this->platform ? $pending : null;
    }

    private function confirmationState(?PendingConnection $pending): string
    {
        return match (true) {
            $pending === null => 'expired',
            $pending->failure() === ConnectFlowException::CANCELLED => 'cancelled',
            $pending->failure() === self::MISSING_PUBLISH_PERMISSION => 'missing_permission',
            $pending->failure() === ConnectFlowException::SESSION_EXPIRED => 'expired',
            $pending->failure() !== null => 'error',
            $pending->isReady() => 'select',
            default => 'expired',
        };
    }

    /**
     * What the page shows of each identity: never its tokens.
     *
     * @return list<array<string, mixed>>
     */
    private function identityCards(PendingConnection $pending): array
    {
        $locked = $pending->lockedIdentityKeys();

        return array_map(fn (array $identity): array => [
            'key' => data_get($identity, 'key'),
            'platform' => data_get($identity, 'platform'),
            'name' => data_get($identity, 'name') ?? data_get($identity, 'username'),
            'username' => data_get($identity, 'username'),
            'avatar' => data_get($identity, 'avatar'),
            'type' => data_get($identity, 'type'),
            'locked' => in_array(data_get($identity, 'key'), $locked, true),
        ], $pending->identities());
    }

    private function startUrl(?PendingConnection $pending, bool $switchingAccount = false): ?string
    {
        $network = str_replace('_', '-', $this->platform->value);
        $name = "app.social.{$network}.connect";

        if (! app('router')->has($name)) {
            return null;
        }

        return route($name, array_filter([
            'reconnect' => $pending?->reconnectId(),
            'return_to' => $pending?->returnPath(),
            'switch' => $switchingAccount ? '1' : null,
        ]));
    }

    /**
     * Copies avatars first, then stores every identity in one transaction, so a
     * failure part-way leaves no half-connected selection behind. A reconnect
     * refuses any identity other than the card being reconnected.
     *
     * @param  list<array<string, mixed>>  $identities
     * @return Collection<int, SocialAccount>
     */
    private function connectIdentities(Workspace $workspace, array $identities, ?SocialAccount $reconnect): Collection
    {
        $prepared = collect($identities)->map(fn (array $identity): array => [$identity, $this->accountValues($identity, $reconnect)]);

        $accounts = DB::transaction(fn (): Collection => $prepared->map(fn (array $entry): SocialAccount => SocialAccount::connectIdentity(
            $workspace,
            SocialPlatform::from((string) data_get($entry, '0.platform')),
            (string) data_get($entry, '0.platform_user_id'),
            $entry[1],
            $reconnect,
        )));

        $accounts->each(fn (SocialAccount $account) => $this->afterConnected($account));

        return $accounts;
    }

    /**
     * @return Collection<string, RepurposeStatus>
     */
    private function repurposeStatesFor(SocialAccount $account): Collection
    {
        return Repurpose::query()
            ->where('workspace_id', $account->workspace_id)
            ->get()
            ->filter(fn (Repurpose $repurpose): bool => $repurpose->dependsOn($account))
            ->pluck('status', 'id');
    }

    /**
     * @param  Collection<string, RepurposeStatus>  $before
     */
    private function flashAccountChange(Collection $before): void
    {
        $after = Repurpose::query()->whereKey($before->keys())->pluck('status', 'id');

        $paused = $before
            ->filter(fn (RepurposeStatus $status, string $id): bool => $status !== RepurposeStatus::Paused
                && $after->get($id) === RepurposeStatus::Paused)
            ->count();

        session()->flash('flash.banner', $paused > 0
            ? trans_choice('accounts.flash.disconnected_paused_repurposes', $paused, ['count' => $paused])
            : __('accounts.flash.disconnected'));
        session()->flash('flash.bannerStyle', 'success');
    }
}
