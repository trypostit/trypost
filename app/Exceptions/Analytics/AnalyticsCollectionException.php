<?php

declare(strict_types=1);

namespace App\Exceptions\Analytics;

use Carbon\CarbonImmutable;
use Exception;

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
}
