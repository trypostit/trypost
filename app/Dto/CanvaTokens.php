<?php

declare(strict_types=1);

namespace App\Dto;

use RuntimeException;

final readonly class CanvaTokens
{
    public function __construct(
        public string $accessToken,
        public string $refreshToken,
        public int $expiresIn,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     */
    public static function fromResponse(array $response): self
    {
        $accessToken = (string) data_get($response, 'access_token');
        $refreshToken = (string) data_get($response, 'refresh_token');

        if ($accessToken === '' || $refreshToken === '') {
            throw new RuntimeException('Canva returned no tokens.');
        }

        return new self($accessToken, $refreshToken, (int) data_get($response, 'expires_in', 0));
    }

    /**
     * Never print the tokens.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['expiresIn' => $this->expiresIn];
    }
}
