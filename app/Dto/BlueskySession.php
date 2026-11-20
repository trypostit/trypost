<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class BlueskySession
{
    public function __construct(
        public string $did,
        public string $handle,
        public ?string $accessToken,
        public ?string $refreshToken,
        public ?bool $emailConfirmed,
    ) {}

    public function hasTokens(): bool
    {
        return $this->accessToken !== null && $this->refreshToken !== null;
    }
}
