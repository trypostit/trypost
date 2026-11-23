<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Enums\SocialAccount\Platform;
use LogicException;

/**
 * The post uses a feature its channel lacks a scope for
 * (`ChannelPermissions::featureBlockingPost()`). Raised before any request, so the
 * failure reads as a permission the user fixes by reconnecting.
 */
final class MissingPermissionException extends SocialPublishException
{
    public function __construct(
        public readonly Platform $target,
        string $userMessage,
    ) {
        parent::__construct(
            userMessage: $userMessage,
            category: ErrorCategory::Permission,
            platformErrorCode: 'missing_permission',
        );
    }

    public static function fromApiResponse(mixed $response): static
    {
        throw new LogicException('A missing permission is found before any request is sent.');
    }

    public function platform(): string
    {
        return $this->target->value;
    }
}
