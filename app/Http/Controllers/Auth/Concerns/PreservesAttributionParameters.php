<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;

trait PreservesAttributionParameters
{
    private const array UTM_KEYS = [
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
    private const array CLICK_ID_KEYS = [
        'gclid',
        'fbclid',
        'li_fat_id',
        'ttclid',
        'rdt_cid',
        'epik',
    ];

    /**
     * UTM values are ours (our own campaign URLs), so truncating them to a
     * safe length is fine. Click IDs are opaque tokens assigned by the ad
     * platform — Google explicitly warns never to truncate or assume a
     * fixed max length for gclid, since it has already grown over time. The
     * `gclid`/etc. columns are `text`, so there's no need to cap them here.
     *
     * @return array<string, string>
     */
    private function extractAttributionParameters(Request $request): array
    {
        $utm = array_filter(
            array_map(
                fn (string $value) => mb_substr($value, 0, 255),
                array_filter($request->only(self::UTM_KEYS), 'is_string'),
            ),
        );

        $clickIds = array_filter($request->only(self::CLICK_ID_KEYS), 'is_string');

        return [...$utm, ...$clickIds];
    }

    private function storeAttributionParameters(Request $request): void
    {
        $parameters = $this->extractAttributionParameters($request);

        if ($parameters !== []) {
            $request->session()->put('attribution_parameters', $parameters);
        }
    }

    /**
     * @return array<string, string>
     */
    private function retrieveAttributionParameters(): array
    {
        return session()->pull('attribution_parameters', []);
    }
}
