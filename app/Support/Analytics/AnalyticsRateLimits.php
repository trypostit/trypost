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
 * - Mastodon: each instance allows 300 requests per 5 minutes per account and, separately, 300 per
 *   5 minutes per IP (60/min, docs.joinmastodon.org/api/rate-limits); every channel of an instance
 *   shares our IP, so guard 50/min per instance.
 *
 * The inbox sync spends the same per-host guards (sharedHostGuard), so inbox polls and analytics
 * jobs never add up past a host's ceiling.
 */
class AnalyticsRateLimits
{
    public const int PER_ACCOUNT_PER_MINUTE = 30;

    public const string LIMITER = 'analytics-publications';

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

    /**
     * The per-host guard of the channel's network, keyed exactly as the
     * analytics jobs' RateLimited middleware stores it, so other jobs can
     * spend from the same counter.
     *
     * @return array{key: string, limit: Limit}|null
     */
    public static function sharedHostGuard(SocialAccount $account): ?array
    {
        $limit = in_array($account->platform, [Platform::Bluesky, Platform::Mastodon], true) ? self::shared($account) : null;

        return $limit ? ['key' => md5(self::LIMITER.$limit->key), 'limit' => $limit] : null;
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
