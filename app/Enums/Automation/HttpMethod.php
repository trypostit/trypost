<?php

declare(strict_types=1);

namespace App\Enums\Automation;

/**
 * HTTP verbs available to the HTTP Request node. Mirrors the
 * frontend HttpMethod const (resources/js/types/automation/http-method.ts).
 */
enum HttpMethod: string
{
    case Get = 'GET';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';

    /**
     * Verbs that carry a request body.
     *
     * @return array<int, self>
     */
    public static function withBody(): array
    {
        return [self::Post, self::Put, self::Patch];
    }
}
