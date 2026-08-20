<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshSocialToken implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public SocialAccount $account) {}

    /**
     * Refresh the token outright rather than verifying it first.
     *
     * A successful refresh already proves the credential is alive — the
     * provider rejects a revoked one with a 4xx — so the verify endpoint adds
     * nothing but cost. On X that endpoint is `GET /2/users/me`, billed as a
     * "User: Read", and verifying a still-valid token left `token_expires_at`
     * untouched: the account stayed inside RefreshExpiringTokens' window and
     * was re-read every 15 minutes until the token actually died, which also
     * left it expired for the stretch between expiry and the next tick.
     */
    public function handle(ConnectionVerifier $verifier): void
    {
        try {
            $verifier->refreshToken($this->account);
            $this->recordVerification();
        } catch (PlatformUnavailableException $e) {
            Log::warning('Token refresh skipped: platform unavailable', [
                'account_id' => $this->account->id,
                'platform' => $this->account->platform->value,
                'error' => $e->getMessage(),
            ]);
        } catch (TokenExpiredException $e) {
            if ($this->accessTokenStillWorks($verifier)) {
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
     * A rejected refresh does not on its own mean the connection is dead.
     * Providers that single-use their refresh_token (X, LinkedIn) reject one a
     * concurrent refresh already consumed while the current access_token keeps
     * working, and an account with no refresh_token at all fails here without
     * any call being made. PublishToSocialPlatform hard-fails every post for a
     * TokenExpired account, so disconnecting on a refresh rejection alone kills
     * posts the access_token would still have published.
     *
     * This is the only place the (often billed) verify endpoint is reached from
     * this job, and only after a refresh has already been rejected. A failure
     * we can't attribute to the token — the platform being down, a network
     * blip — leaves the account alone rather than disconnecting it on noise.
     */
    private function accessTokenStillWorks(ConnectionVerifier $verifier): bool
    {
        // A concurrent refresh may have persisted a new pair while ours was in
        // flight, which is why ours was rejected. This instance still holds the
        // token that was rotated away, so reload before judging it — otherwise
        // the winner's healthy account gets disconnected.
        $this->account->refresh();

        try {
            return $verifier->verifyAccessToken($this->account);
        } catch (TokenExpiredException) {
            return false;
        } catch (Throwable $e) {
            Log::warning('Access token fallback check failed after a rejected refresh', [
                'account_id' => $this->account->id,
                'platform' => $this->account->platform->value,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Record the refresh as a verification, so the daily sweep and the
     * pre-publish check can skip their own (often billed) verify call.
     *
     * Only a refresh that actually happened proves anything, and only one that
     * came back with a usable token: TokenRefreshClient classifies on HTTP
     * status alone and never inspects the body, so a 200 carrying an empty
     * token would otherwise be recorded as healthy. This is deliberately not
     * done inside ConnectionVerifier::refreshToken() — refreshThenVerify()
     * calls it and can still fail on the verify that follows, and a stamp
     * written there would vouch for a credential nothing ever confirmed.
     */
    private function recordVerification(): void
    {
        if (! $this->account->platform->hasTokenRefreshFlow()) {
            return;
        }

        if (blank($this->account->access_token)) {
            return;
        }

        $this->account->update(['last_verified_at' => now()]);
    }
}
