<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Str;

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
