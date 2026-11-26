<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Support\Arr;

/**
 * Raised when a social platform operation is temporarily unavailable or still
 * processing. Distinct from TokenExpiredException because the account's token
 * is not provably invalid. Callers should retry later instead of disconnecting
 * the user or reporting a definitive publish failure.
 */
class PlatformUnavailableException extends Exception
{
    /**
     * A `retryBackoffSeconds` list waits each entry in turn before the next
     * attempt and fails once the list is spent, in place of a fixed
     * `retryDelaySeconds` and `maxRetries`.
     *
     * @param  array<string, mixed>  $context
     * @param  list<int>  $retryBackoffSeconds
     */
    public function __construct(
        string $message = 'Platform API is unavailable',
        public ?int $httpStatus = null,
        public array $context = [],
        public ?int $retryDelaySeconds = null,
        public ?int $maxRetries = null,
        public array $retryBackoffSeconds = [],
    ) {
        parent::__construct($message);
    }

    public function hasOwnRetryPolicy(): bool
    {
        if (filled($this->retryBackoffSeconds)) {
            return true;
        }

        if (filled($this->retryDelaySeconds)) {
            return true;
        }

        return filled($this->maxRetries);
    }

    public function maxRetriesOr(int $default): int
    {
        if (filled($this->retryBackoffSeconds)) {
            return count($this->retryBackoffSeconds);
        }

        return $this->maxRetries ?? $default;
    }

    /**
     * The wait before the given retry, counted from 1.
     */
    public function retryDelayFor(int $retry, int $default): int
    {
        if (filled($this->retryBackoffSeconds)) {
            return Arr::get($this->retryBackoffSeconds, $retry - 1, Arr::last($this->retryBackoffSeconds));
        }

        return $this->retryDelaySeconds ?? $default;
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
