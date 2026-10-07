<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

enum ErrorCategory: string
{
    case MediaFormat = 'media_format';
    case RateLimit = 'rate_limit';
    case Permission = 'permission';
    case ContentPolicy = 'content_policy';
    case ServerError = 'server_error';
    case Unknown = 'unknown';
    case PlatformUnavailable = 'platform_unavailable';
    case Timeout = 'timeout';
    case TokenExpired = 'token_expired';
    case JobFailed = 'job_failed';

    public function isResumable(): bool
    {
        return match ($this) {
            self::PlatformUnavailable, self::Timeout, self::TokenExpired, self::JobFailed => true,
            default => false,
        };
    }

    public function needsUserAction(): bool
    {
        return match ($this) {
            self::MediaFormat, self::RateLimit, self::Permission, self::ContentPolicy => true,
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>|null  $context
     */
    public static function tryFromContext(?array $context): ?self
    {
        $category = data_get($context, 'category');

        return is_string($category) ? self::tryFrom($category) : null;
    }
}
