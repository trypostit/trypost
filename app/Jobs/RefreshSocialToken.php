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
        } catch (PlatformUnavailableException $e) {
            Log::warning('Token refresh skipped: platform unavailable', [
                'account_id' => $this->account->id,
                'platform' => $this->account->platform->value,
                'error' => $e->getMessage(),
            ]);
        } catch (TokenExpiredException $e) {
            $this->account->markAsTokenExpired($e->getMessage());
        } catch (Throwable $e) {
            Log::warning('Proactive token refresh failed', [
                'account_id' => $this->account->id,
                'platform' => $this->account->platform->value,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
