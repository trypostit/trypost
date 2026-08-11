<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth\Concerns;

use App\Support\AttributionKeys;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait PreservesAttributionParameters
{
    /**
     * UTM values are ours (our own campaign URLs), so truncating them to a
     * safe length is fine. Click IDs are opaque tokens assigned by the ad
     * platform — Google explicitly warns never to truncate or assume a
     * fixed max length for gclid, since it has already grown over time. The
     * `gclid`/etc. columns are `text`, so there's no need to cap them here.
     *
     * A key present with an empty value (e.g. `?utm_source=&gclid=`, which
     * some ad/email templates always append) is filtered out along with
     * absent keys, so it never overwrites a null column with `''`.
     *
     * @return array<string, string>
     */
    private function extractAttributionParameters(Request $request): array
    {
        $utm = collect($request->only(AttributionKeys::UTM))
            ->filter(fn ($value) => is_string($value))
            ->map(fn (string $value) => Str::limit($value, 255, ''));

        $clickIds = collect($request->only(AttributionKeys::CLICK_ID))
            ->filter(fn ($value) => is_string($value));

        return $utm->merge($clickIds)->filter()->all();
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
