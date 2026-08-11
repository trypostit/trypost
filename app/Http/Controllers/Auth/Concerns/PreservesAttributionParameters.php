<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use Illuminate\Http\Request;

trait PreservesAttributionParameters
{
    /**
     * UTM parameters plus per-platform ad click IDs (Google, Meta, LinkedIn,
     * TikTok, Reddit, Pinterest). Adding a new ad network's click ID is just
     * one more key here.
     */
    private const array ATTRIBUTION_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content',
        'gclid',
        'fbclid',
        'li_fat_id',
        'ttclid',
        'rdt_cid',
        'epik',
    ];

    /**
     * @return array<string, string>
     */
    private function extractAttributionParameters(Request $request): array
    {
        return array_filter(
            array_map(
                fn (string $value) => mb_substr($value, 0, 255),
                array_filter($request->only(self::ATTRIBUTION_KEYS), 'is_string'),
            ),
        );
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
