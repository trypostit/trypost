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

    public function handle(ConnectionVerifier $verifier): void
    {
        try {
            if ($this->account->platform->extendsAccessTokenOnRefresh()) {
                // Instagram/Threads extend the long-lived token itself and
                // can't be refreshed once expired, so extend it while it's
                // still valid instead of waiting for it to fail.
                $verifier->refreshToken($this->account);
            } else {
                $verifier->verify($this->account);
            }
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
