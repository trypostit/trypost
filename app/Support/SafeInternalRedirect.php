<?php

declare(strict_types=1);

namespace App\Support;

final class SafeInternalRedirect
{
    /**
     * Only allow same-app path redirects — never an absolute/external URL and
     * never a protocol-relative one (`//evil.com` is parsed as external by
     * browsers despite starting with a single `/`).
     */
    public static function resolve(mixed $redirect): ?string
    {
        if (! is_string($redirect) || $redirect === '') {
            return null;
        }

        if (str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return $redirect;
        }

        return null;
    }
}
