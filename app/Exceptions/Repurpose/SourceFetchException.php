<?php

declare(strict_types=1);

namespace App\Exceptions\Repurpose;

use App\Services\Social\Meta\GraphError;
use Illuminate\Http\Client\Response;
use RuntimeException;

class SourceFetchException extends RuntimeException
{
    public function __construct(private readonly Response $response)
    {
        parent::__construct((string) data_get($response->json(), 'error.message', $response->body()));
    }

    public function isTransient(): bool
    {
        return GraphError::isTransientFailure($this->response);
    }
}
