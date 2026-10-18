<?php

declare(strict_types=1);

namespace App\Support\Inbox;

use App\Services\Social\TokenRedactor;
use RuntimeException;
use Throwable;

/**
 * What an inbox job stores or reports about a failure, with any access token
 * in the message replaced. A message that needed redacting is reported as a
 * plain exception carrying the redacted text and the original class.
 */
class RedactedError
{
    public static function message(Throwable $exception): string
    {
        return mb_substr((string) TokenRedactor::redact($exception->getMessage()), 0, 255);
    }

    public static function report(Throwable $exception): void
    {
        $message = (string) TokenRedactor::redact($exception->getMessage());

        if ($message === $exception->getMessage()) {
            report($exception);

            return;
        }

        $class = $exception::class;

        report(new RuntimeException("{$class}: {$message}"));
    }
}
