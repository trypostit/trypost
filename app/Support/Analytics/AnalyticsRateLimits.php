<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Cache\RateLimiting\Limit;

/**
 * Queue rate limits for analytics jobs, keyed where each network enforces its limit.
 *
 * Every account gets its own budget of 30 jobs per minute (43,200 a day), so one large
 * customer can no longer starve every other account of the same network:
 * Meta (Instagram, Facebook, Threads) and Pinterest limit per user or Page token.
 *
 * Networks that limit per app, per project or per IP also get a shared guard below the
 * documented ceiling:
 * - X: GET /2/users/:id/tweets allows 10,000 requests per 15 minutes per app (~666/min); guard 600/min.
 * - TikTok: 600 requests per minute per endpoint; guard 500/min.
 * - YouTube has no shared guard. A metrics job reads `videos.list` in batches of 50 ids (one Data API
 *   unit for 50 videos) and one YouTube Analytics API report; uploads have their own quota. A
 *   `quotaExceeded` or 429 from Google is retried as rate_limited, and more quota is requested from
 *   Google when that happens.
 * - Bluesky: a PDS allows 3,000 requests per 5 minutes per IP over all endpoints (600/min,
 *   docs.bsky.app "Rate Limits", `global-ip` in @atproto/pds rate-limits.ts), shared with publishing
 *   through the same host; guard 500/min per PDS host. The public AppView documents no number.
 * - Mastodon: 300 requests per 5 minutes per IP on each instance (60/min); guard 50/min per instance.
 */
class AnalyticsRateLimits
{
    public const int PER_ACCOUNT_PER_MINUTE = 30;

    /** @return list<Limit> */
    public static function for(?SocialAccount $account): array
    {
        if (! $account) {
            return [Limit::perMinute(self::PER_ACCOUNT_PER_MINUTE)->by('missing')];
        }

        $network = $account->platform->network();
        $limits = [Limit::perMinute(self::PER_ACCOUNT_PER_MINUTE)->by("{$network}:account:{$account->id}")];
        $shared = self::shared($account);

        if ($shared) {
            $limits[] = $shared;
        }

        return $limits;
    }

    private static function shared(SocialAccount $account): ?Limit
    {
        $network = $account->platform->network();

        return match ($account->platform) {
            Platform::X => Limit::perMinute(600)->by("{$network}:app"),
            Platform::TikTok => Limit::perMinute(500)->by("{$network}:app"),
            Platform::Bluesky => Limit::perMinute(500)->by("{$network}:pds:".parse_url((string) data_get($account->meta, 'service', config('trypost.platforms.bluesky.default_service')), PHP_URL_HOST)),
            Platform::Mastodon => Limit::perMinute(50)->by("{$network}:instance:{$account->mastodonInstance()}"),
            default => null,
        };
    }
}
