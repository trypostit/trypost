<?php

declare(strict_types=1);

namespace App\Exceptions\RssFeed;

use RuntimeException;

class RssFeedFetchException extends RuntimeException
{
    public function __construct(public readonly string $errorKey)
    {
        parent::__construct($errorKey);
    }
}
