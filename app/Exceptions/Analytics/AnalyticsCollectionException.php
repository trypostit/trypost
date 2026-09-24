<?php

declare(strict_types=1);

namespace App\Exceptions\Analytics;

use App\Support\Analytics\RetryAfter;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Http\Client\Response;

class AnalyticsCollectionException extends Exception
{
    public function __construct(
        public readonly string $category,
        string $message,
        public readonly ?CarbonImmutable $retryAt = null,
    ) {
        parent::__construct($message);
    }

    public static function unsupported(string $message): self
    {
        return new self('unsupported', $message);
    }

    public static function malformed(string $message): self
    {
        return new self('malformed', $message);
    }

    public static function fromResponse(Response $response, string $operation): self
    {
        $reason = (string) data_get($response->json(), 'error.errors.0.reason', '');
        $category = match (true) {
            $response->status() === 429,
            in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'], true) => 'rate_limited',
            $response->status() === 401 => 'authentication',
            $response->status() === 403 => 'permission',
            $response->serverError() => 'transient',
            default => 'malformed',
        };

        return new self(
            $category,
            "{$operation} failed with HTTP {$response->status()}",
            RetryAfter::from($response),
        );
    }
}
