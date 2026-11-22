<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class OAuthScopes
{
    /** @return list<string> */
    public static function parse(string $scopes): array
    {
        return Str::matchAll('/[^\s,]+/', $scopes)->all();
    }

    /** @return list<string> */
    public static function parseSpaceSeparated(string $scopes): array
    {
        return Str::of($scopes)->explode(' ')->filter()->values()->all();
    }

    /** @param array<int, string> $scopes */
    public static function commaSeparated(array $scopes): string
    {
        return Arr::join($scopes, ',');
    }

    /** @param array<int, string> $scopes */
    public static function spaceSeparated(array $scopes): string
    {
        return Arr::join($scopes, ' ');
    }
}
