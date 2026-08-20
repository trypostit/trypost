<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Enums\SocialAccount\Status;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class VerifyWorkspaceConnections implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    // How long a recorded verification (SocialAccount::last_verified_at) is
    // trusted before this sweep re-checks the account. RefreshSocialToken
    // stamps that field on every successful token refresh, and a refresh does
    // more than the verify endpoint does: it replaces the access token rather
    // than inspecting it, so there is nothing left for a billed read to
    // confirm.
    //
    // For short-TTL platforms this means the sweep never calls verify() again
    // — X and Bluesky tokens live 2h and are refreshed ~90 minutes apart, so
    // the stamp is never stale at the daily tick. That is intended, not an
    // oversight: the refresh detects a revoked or dead credential 16× more
    // often than this sweep did, for free. What it cannot see (a suspended
    // account whose refresh still succeeds) surfaces at publish time.
    private const VERIFIED_WITHIN_HOURS = 12;

    public function __construct(public Workspace $workspace) {}

    public function handle(ConnectionVerifier $verifier): void
    {
        $accounts = $this->workspace->socialAccounts()
            ->with('workspace.owner')
            ->whereIn('status', [Status::Connected, Status::TokenExpired])
            ->get();

        if ($accounts->isEmpty()) {
            return;
        }

        $disconnectedAccounts = collect();

        foreach ($accounts as $account) {
            if ($this->recentlyProvenValid($account)) {
                continue;
            }

            if ($this->verifyAccount($verifier, $account)) {
                continue;
            }

            $disconnectedAccounts->push($account);
        }

        if ($disconnectedAccounts->isNotEmpty()) {
            $this->notifyOwner($disconnectedAccounts);
        }
    }

    /**
     * Only a Connected account can be skipped. A TokenExpired one still needs
     * the call: verifying it is how it gets promoted back to Connected, so
     * trusting a stale stamp would strand a recovered account forever.
     */
    private function recentlyProvenValid(SocialAccount $account): bool
    {
        return $account->status === Status::Connected
            && $account->last_verified_at !== null
            && $account->last_verified_at->isAfter(now()->subHours(self::VERIFIED_WITHIN_HOURS));
    }

    private function verifyAccount(ConnectionVerifier $verifier, SocialAccount $account): bool
    {
        try {
            $verifier->verify($account);
            $account->update(['last_verified_at' => now()]);

            // Promote here, not on this method's return value: it also returns
            // true for "could not check, don't disconnect", and reviving an
            // account on an outage tells the owner a reconnect worked when
            // nothing was verified at all.
            if ($account->status === Status::TokenExpired) {
                $account->markAsConnected();
            }

            return true;
        } catch (PlatformUnavailableException $e) {
            Log::warning('Social account verification skipped: platform unavailable', [
                'account_id' => $account->id,
                'platform' => $account->platform->value,
                'error' => $e->getMessage(),
            ]);

            return true;
        } catch (TokenExpiredException $e) {
            Log::warning('Social account connection is invalid', [
                'account_id' => $account->id,
                'platform' => $account->platform->value,
                'error' => $e->getMessage(),
            ]);

            if ($account->status === Status::TokenExpired) {
                // Second failure — escalate to Disconnected (no individual notification,
                // the batch notification from notifyOwner() handles it)
                $account->update([
                    'status' => Status::Disconnected,
                    'error_message' => $e->getMessage(),
                    'disconnected_at' => now(),
                ]);
            } else {
                // First failure — mark as TokenExpired (softer state).
                // Suppress per-account notification; the batch notifyOwner()
                // sends a single summary email for all failures at the end.
                $account->markAsTokenExpired($e->getMessage(), notify: false);
            }

            return false;
        } catch (\Exception $e) {
            Log::error('Failed to verify social account connection', [
                'account_id' => $account->id,
                'platform' => $account->platform->value,
                'error' => $e->getMessage(),
            ]);

            // Unknown error — don't mark as disconnected, retry next time
            return true;
        }
    }

    /**
     * @param  Collection<int, SocialAccount>  $disconnectedAccounts
     */
    private function notifyOwner(Collection $disconnectedAccounts): void
    {
        $owner = $this->workspace->owner;

        if (! $owner) {
            return;
        }

        $accountNames = $disconnectedAccounts
            ->map(fn ($account) => $account->platform->label().' ('.$account->handle().')')
            ->implode(', ');

        SendNotification::dispatch(
            user: $owner,
            workspaceId: $this->workspace->id,
            type: Type::AccountDisconnected,
            channel: Channel::Both,
            title: $disconnectedAccounts->count().' '.($disconnectedAccounts->count() === 1 ? 'account' : 'accounts').' disconnected',
            body: $accountNames,
            data: ['workspace_id' => $this->workspace->id],
            mailable: new WorkspaceConnectionsDisconnected($this->workspace, $disconnectedAccounts),
        );
    }
}
