<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Services\Social\TokenRedactor;
use RuntimeException;

abstract class SocialPublishException extends RuntimeException
{
    public function __construct(
        public readonly string $userMessage,
        public readonly ErrorCategory $category,
        public readonly ?string $platformErrorCode = null,
        public readonly ?string $rawResponse = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $userMessage);
    }

    /**
     * @return array{platform: string, category: string, platform_error_code: ?string, user_message: string, raw_response: ?string}
     */
    public function context(): array
    {
        return [
            'platform' => $this->platform(),
            'category' => $this->category->value,
            'platform_error_code' => $this->platformErrorCode,
            'user_message' => $this->userMessage,
            'raw_response' => TokenRedactor::redact($this->rawResponse),
        ];
    }

    abstract public static function fromApiResponse(mixed $response): static;

    abstract public function platform(): string;
}
