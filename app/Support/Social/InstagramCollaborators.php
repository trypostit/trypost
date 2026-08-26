<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Arr;

final class InstagramCollaborators
{
    public const int MAX = 3;

    private const string USERNAME_PATTERN = '/^(?!.*\.\.)(?!\.)[A-Za-z0-9._]{1,30}(?<!\.)\z/';

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function applyToMeta(array $meta, ?string $ownUsername = null): array
    {
        unset($meta['collaborators_with']);

        if (! array_key_exists('collaborators', $meta)) {
            return $meta;
        }

        $meta['collaborators'] = self::normalize(data_get($meta, 'collaborators'), $ownUsername);

        return $meta;
    }

    /** @return list<string> */
    public static function normalize(mixed $value, ?string $ownUsername = null): array
    {
        return self::walk($value, $ownUsername)['accepted'];
    }

    public static function isSameUsername(string $left, ?string $right): bool
    {
        return filled($right) && ($key = self::key($left)) !== '' && $key === self::key($right);
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, self::bare($username)) === 1;
    }

    /**
     * @return array{items: array<int|string, 'invalid'|'duplicate'|'self'>, exceedsMax: bool}
     */
    public static function failures(mixed $value, ?string $ownUsername): array
    {
        return Arr::only(self::walk($value, $ownUsername), ['items', 'exceedsMax']);
    }

    /**
     * Graph wants one parameter: JSON list `["a","b"]`, not `collaborators[0]=`.
     *
     * @return array<string, string>
     */
    public static function payload(mixed $value, ?string $ownUsername = null): array
    {
        $usernames = self::normalize($value, $ownUsername);

        return $usernames === []
            ? []
            : ['collaborators' => json_encode($usernames, JSON_THROW_ON_ERROR)];
    }

    /**
     * Shared by normalize() and failures() so accepted list and errors agree.
     *
     * @return array{accepted: list<string>, items: array<int|string, 'invalid'|'duplicate'|'self'>, exceedsMax: bool}
     */
    private static function walk(mixed $value, ?string $ownUsername): array
    {
        $accepted = [];
        $items = [];

        foreach (is_array($value) ? $value : [] as $index => $item) {
            $reason = match (true) {
                ! is_string($item) || ! self::isValidUsername($item) => 'invalid',
                self::isSameUsername($item, $ownUsername) => 'self',
                isset($accepted[self::key($item)]) => 'duplicate',
                default => null,
            };

            if ($reason === null) {
                $accepted[self::key($item)] = self::bare($item);
            } else {
                $items[$index] = $reason;
            }
        }

        return [
            'accepted' => array_slice(array_values($accepted), 0, self::MAX),
            'items' => $items,
            'exceedsMax' => count($accepted) > self::MAX,
        ];
    }

    private static function bare(string $username): string
    {
        return ltrim(trim($username), '@');
    }

    private static function key(string $username): string
    {
        return mb_strtolower(self::bare($username));
    }
}
