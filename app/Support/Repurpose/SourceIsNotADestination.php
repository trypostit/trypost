<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

class SourceIsNotADestination
{
    /**
     * @param  array<int, mixed>  $destinations
     */
    public static function addErrors(Validator $validator, array $destinations, ?string $sourceAccountId): void
    {
        foreach (self::offendingKeys($destinations, $sourceAccountId) as $key) {
            $validator->errors()->add($key, __('repurposes.errors.destination_is_source'));
        }
    }

    /**
     * @param  array<int, mixed>  $destinations
     */
    public static function assert(array $destinations, ?string $sourceAccountId): void
    {
        $keys = self::offendingKeys($destinations, $sourceAccountId);

        if ($keys !== []) {
            throw ValidationException::withMessages(array_fill_keys(
                $keys,
                __('repurposes.errors.destination_is_source'),
            ));
        }
    }

    /**
     * @param  array<int, mixed>  $destinations
     * @return array<int, string>
     */
    private static function offendingKeys(array $destinations, ?string $sourceAccountId): array
    {
        if ($sourceAccountId === null) {
            return [];
        }

        $keys = [];

        foreach ($destinations as $index => $destination) {
            if (data_get($destination, 'social_account_id') === $sourceAccountId) {
                $keys[] = "destinations.{$index}.social_account_id";
            }
        }

        return $keys;
    }
}
