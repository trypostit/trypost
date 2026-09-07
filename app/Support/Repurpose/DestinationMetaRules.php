<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Str;

/**
 * Re-keys the shared per-platform meta rules from `platforms.*` to the
 * `destinations.*` a repurpose submits them under. The rules themselves stay in
 * {@see PostPlatformMetaRules}: validated() strips any key without a rule, so a
 * meta field spelled out here instead would be dropped by every other surface.
 */
class DestinationMetaRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return self::reKey(PostPlatformMetaRules::rules());
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return self::reKey(PostPlatformMetaRules::messages());
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return self::reKey(PostPlatformMetaRules::attributes());
    }

    /**
     * @param  array<string, mixed>  $entries
     * @return array<string, mixed>
     */
    private static function reKey(array $entries): array
    {
        $destinations = [];

        foreach ($entries as $key => $entry) {
            if (! Str::startsWith($key, 'platforms.*.meta')) {
                continue;
            }

            $destinations[Str::replaceFirst('platforms.*.', 'destinations.*.', $key)] = $entry;
        }

        return $destinations;
    }
}
