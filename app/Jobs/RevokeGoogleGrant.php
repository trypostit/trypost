<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Hands a removed YouTube or Google Business channel's access back to Google,
 * as Google's API Services User Data Policy asks, so the user no longer sees
 * the app among the apps with access to their account.
 *
 * Revoking a token ends the user's whole grant to the OAuth client, refresh
 * tokens included, so it is skipped while another channel still publishes
 * with that Google user's grant: the other channels of the same login (several
 * YouTube channels or locations), or any channel in another workspace. Best
 * effort: a failed revoke never brings the channel back.
 *
 * @see https://developers.google.com/identity/protocols/oauth2/web-server#tokenrevoke
 */
class RevokeGoogleGrant implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public Platform $platform,
        public string $googleUserId,
        public string $token,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 600];
    }

    public static function forRemoved(SocialAccount $account): void
    {
        if (! in_array($account->platform, [Platform::YouTube, Platform::GoogleBusiness], true)) {
            return;
        }

        $token = $account->refresh_token ?: $account->access_token;
        $googleUserId = data_get($account->meta, 'google_user_id');

        // Without the Google user there is no telling whether another channel
        // still runs on the same grant, so the grant is left alone.
        if (blank($token) || blank($googleUserId)) {
            return;
        }

        self::dispatch($account->platform, (string) $googleUserId, $token)->afterCommit();
    }

    public function handle(): void
    {
        if ($this->grantStillInUse()) {
            return;
        }

        $response = Http::asForm()
            ->timeout(15)
            ->post(config("trypost.platforms.{$this->platform->value}.oauth_api").'/revoke', [
                'token' => $this->token,
            ]);

        // 400 is Google's answer for a token that is already revoked or expired:
        // the grant is gone either way.
        if ($response->successful() || $response->status() === Response::HTTP_BAD_REQUEST) {
            return;
        }

        // A Google outage or rate limit is worth another try; a connection
        // error throws too.
        if ($response->serverError() || $response->tooManyRequests()) {
            $response->throw();
        }

        Log::warning('Google token revoke refused', [
            'platform' => $this->platform->value,
            'status' => $response->status(),
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Google token revoke failed', [
            'platform' => $this->platform->value,
            'error' => $exception?->getMessage(),
        ]);
    }

    private function grantStillInUse(): bool
    {
        return SocialAccount::query()
            ->whereIn('platform', [Platform::YouTube, Platform::GoogleBusiness])
            ->where('meta->google_user_id', $this->googleUserId)
            ->exists();
    }
}
