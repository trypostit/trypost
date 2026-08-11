<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
        $utm = collect($request->only(self::UTM_KEYS))
            ->filter(fn ($value) => is_string($value))
            ->map(fn (string $value) => Str::limit($value, 255, ''));

        $clickIds = collect($request->only(self::CLICK_ID_KEYS))
            ->filter(fn ($value) => is_string($value));

        return $utm->merge($clickIds)->all();
    }

    private function storeAttributionParameters(Request $request): void
    {
        $request->session()->put('attribution_parameters', $this->extractAttributionParameters($request));
    }

    /**
     * @return array<string, string>
     */
    private function retrieveAttributionParameters(): array
    {
        return session()->pull('attribution_parameters', []);
    }
}
