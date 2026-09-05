<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Validation rules for a repurpose, shared by the web, API and MCP surfaces.
 *
 * Destination meta is NOT declared here: it is re-keyed from
 * `PostPlatformMetaRules` so a platform's meta is defined in exactly one place.
 * A key without a rule is stripped by `validated()`, so duplicating the list
 * here would silently drop fields on whichever surface forgot to update.
 */
class RepurposeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'source_social_account_id' => ['required', 'string', 'uuid'],
            'destinations' => ['sometimes', 'array'],
            'destinations.*.social_account_id' => ['required', 'string', 'uuid'],
            'destinations.*.content_type' => ['required', 'string', Rule::enum(ContentType::class)],
            ...self::destinationMetaRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function destinationMetaRules(): array
    {
        $rules = [];

        foreach (PostPlatformMetaRules::rules() as $key => $rule) {
            if (! Str::startsWith($key, 'platforms.*.meta')) {
                continue;
            }

            $rules[Str::replaceFirst('platforms.*.', 'destinations.*.', $key)] = $rule;
        }

        return $rules;
    }
}
