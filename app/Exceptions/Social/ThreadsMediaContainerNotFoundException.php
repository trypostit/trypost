<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

final class ThreadsMediaContainerNotFoundException extends ThreadsPublishException
{
    public static function from(ThreadsPublishException $exception): self
    {
        return new self(
            userMessage: $exception->userMessage,
            category: $exception->category,
            platformErrorCode: $exception->platformErrorCode,
            rawResponse: $exception->rawResponse,
        );
    }
}
