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

    /**
     * Graph rejects the whole read when one requested field is not available to
     * the token's login type, which is how it answers for the fields Meta marks
     * as Facebook-login only.
     */
    public function isUnknownField(): bool
    {
        return (int) data_get($this->response->json(), 'error.code') === 100;
    }
}
