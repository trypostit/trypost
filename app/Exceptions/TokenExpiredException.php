<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class TokenExpiredException extends Exception
{
    public function __construct(
        string $message = 'Access token has expired or been revoked',
        public ?string $platformErrorCode = null
    ) {
        parent::__construct($message);
    }

    /**
     * @return array{platform_error_code: ?string}
     */
    public function context(): array
    {
        return [
            'platform_error_code' => $this->platformErrorCode,
        ];
    }
}
