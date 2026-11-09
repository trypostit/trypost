<?php

declare(strict_types=1);

namespace App\Enums\X;

use Illuminate\Support\Uri;

/**
 * The problem types X documents for its API errors, by the path of the `type` URI:
 * its host moved from api.twitter.com to api.x.com, its path did not.
 */
enum ProblemType: string
{
    case ResourceNotFound = '2/problems/resource-not-found';

    /**
     * @param  array<string, mixed>  $error
     */
    public static function of(array $error): ?self
    {
        return self::tryFrom(Uri::of((string) data_get($error, 'type'))->path());
    }
}
