<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

/**
 * Raised when a social platform operation is temporarily unavailable or still
 * processing. Distinct from TokenExpiredException because the account's token
 * is not provably invalid. Callers should retry later instead of disconnecting
 * the user or reporting a definitive publish failure.
 */
class PlatformUnavailableException extends Exception
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Platform API is unavailable',
        public ?int $httpStatus = null,
        public array $context = [],
        public ?int $retryDelaySeconds = null,
        public ?int $maxRetries = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return array_filter([
            'http_status' => $this->httpStatus,
            ...$this->context,
        ], fn (mixed $value): bool => $value !== null);
    }
}
