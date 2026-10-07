<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Enums\SocialAccount\Platform;
use LogicException;

/**
 * The post (or a thread reply) does not fit the target account's limit at
 * publish time. Raised before any request, so it never wraps a response.
 */
final class ContentLimitException extends SocialPublishException
{
    public function __construct(
        public readonly Platform $target,
        string $userMessage,
    ) {
        parent::__construct(
            userMessage: $userMessage,
            category: ErrorCategory::ContentPolicy,
            platformErrorCode: 'content_too_long',
        );
    }

    public static function exceeds(Platform $platform, int $max, int $provided): self
    {
        return new self($platform, __('posts.errors.content_too_long', [
            'platform' => $platform->label(),
            'max' => $max,
            'provided' => $provided,
        ]));
    }

    public static function fromApiResponse(mixed $response): static
    {
        throw new LogicException('A content limit is measured before any request is sent.');
    }

    public function platform(): string
    {
        return $this->target->value;
    }
}
