<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\SourceFormat;
use App\Support\PostPlatformMetaRules;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RepurposeRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'source_social_account_id' => ['required', 'string', 'uuid'],
            'source_format' => ['sometimes', Rule::enum(SourceFormat::class)],
            'destinations' => ['sometimes', 'array'],
            'destinations.*.social_account_id' => ['required', 'string', 'uuid'],
            'destinations.*.content_type' => [
                'required',
                'string',
                Rule::enum(ContentType::class),
                fn (string $attribute, mixed $value, callable $fail) => ContentType::tryFrom((string) $value)?->supportsVideo() === false
                    ? $fail(__('repurposes.errors.destination_needs_video'))
                    : null,
            ],
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
