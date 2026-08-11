<?php

declare(strict_types=1);

namespace App\Support;

/**
 * UTM parameters and ad click IDs captured before signup (query string ->
 * session -> persisted on the users row), shared by
 * App\Http\Controllers\Auth\Concerns\PreservesAttributionParameters (capture)
 * and App\Jobs\PostHog\SyncUser (forwarding to PostHog).
 */
final class AttributionKeys
{
    public const array UTM = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
    ];

    /**
     * Per-platform ad click IDs (Google, Meta, LinkedIn, TikTok, Reddit,
     * Pinterest). Adding a new ad network's click ID is just one more key
     * here.
     */
    public const array CLICK_ID = [
        'gclid',
        'fbclid',
        'li_fat_id',
        'ttclid',
        'rdt_cid',
        'epik',
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [...self::UTM, ...self::CLICK_ID];
    }
}
