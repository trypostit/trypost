<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshSocialToken implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // token_expires_at only moves once this job runs, so a backlogged queue
    // would stack one job per tick for the same account.
    public int $uniqueFor = 900;

    public function __construct(public SocialAccount $account) {}

    public function uniqueId(): string
    {
        return $this->account->id;
    }

    /**
     * Refresh outright rather than verifying first: a refresh replaces the
     * access token, leaving nothing for a verify call — billed as a "User:
     * Read" on X — to confirm.
     */
    public function handle(ConnectionVerifier $verifier): void
    {
        try {
            if ($verifier->refreshToken($this->account)) {
                $this->recordVerification();
            }
        } catch (PlatformUnavailableException $e) {
            Log::warning('Token refresh skipped: platform unavailable', [
                'account_id' => $this->account->id,
                'platform' => $this->account->platform->value,
                'error' => $e->getMessage(),
            ]);
        } catch (TokenExpiredException $e) {
            // Instagram and Threads extend their token in place and cannot
            // renew it once expired, so a rejected extension means the
            // connection is already doomed — say so while reconnecting still
            // helps. Elsewhere a rejection often just means we lost a race.
            if (! $this->account->platform->extendsAccessTokenOnRefresh()
                && $this->accessTokenStillWorks($verifier)) {
                return;
            }

            $this->account->markAsTokenExpired($e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Proactive token refresh failed', [
                'account_id' => $this->account->id,
                'platform' => $this->account->platform->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The only place this job reaches the (billed) verify endpoint, and only
     * once a refresh has been rejected — which on X and Bluesky usually means
     * a concurrent refresh consumed the single-use refresh_token first.
     */
    private function accessTokenStillWorks(ConnectionVerifier $verifier): bool
    {
        try {
            // The winner of that race persisted a new pair; ours is stale.
            $this->account->refresh();

            if (! $verifier->verifyAccessToken($this->account)) {
                return false;
            }

            $this->recordVerification();

            return true;
        } catch (TokenExpiredException) {
            return false;
        } catch (PlatformUnavailableException|ConnectionException|ModelNotFoundException $e) {
            // Only these three earn the benefit of the doubt. Reading any other
            // failure as "healthy" leaves the account Connected forever while
            // every publish hard-fails.
            Log::warning('Access token fallback check failed after a rejected refresh', [
                'account_id' => $this->account->id,
                'platform' => $this->account->platform->value,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Lets the daily sweep and the pre-publish check skip a verify of their
     * own. Not done inside refreshToken(): refreshThenVerify() calls that too
     * and can still fail on the verify that follows.
     */
    private function recordVerification(): void
    {
        $this->account->update(['last_verified_at' => now()]);
    }
}
