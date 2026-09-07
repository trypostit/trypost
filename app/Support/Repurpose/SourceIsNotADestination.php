<?php

declare(strict_types=1);

namespace App\Support\Repurpose;

use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

/**
 * A repurpose copies what an account posts elsewhere. Pointing it back at that
 * same account would republish the video onto the profile it came from.
 *
 * Checked after the field rules rather than as one of them: the source id it
 * compares against is a *different* field, and a rule object is built while
 * rules() is assembled — before anything has been validated, when the payload
 * is still whatever the client sent. Running here means both sides are already
 * strings that passed `uuid`.
 */
class SourceIsNotADestination
{
    /**
     * For request-driven flows, from withValidator().
     *
     * @param  array<int, mixed>  $destinations
     */
    public static function addErrors(Validator $validator, array $destinations, ?string $sourceAccountId): void
    {
        foreach (self::offendingKeys($destinations, $sourceAccountId) as $key) {
            $validator->errors()->add($key, __('repurposes.errors.destination_is_source'));
        }
    }

    /**
     * For MCP, which validates in one call and has no validator to add to.
     *
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
