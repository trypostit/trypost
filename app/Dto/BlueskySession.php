<?php

declare(strict_types=1);

namespace App\Dto;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Validator;

final readonly class BlueskySession
{
    private function __construct(
        public string $did,
        public string $handle,
        public ?string $accessToken,
        public ?string $refreshToken,
        public ?bool $emailConfirmed,
    ) {}

    public static function fromResponse(Response $response): ?self
    {
        $data = $response->json();

        if (! $response->successful() || ! is_array($data)) {
            return null;
        }

        $emailConfirmed = data_get($data, 'emailConfirmed');

        if ($emailConfirmed !== null && ! is_bool($emailConfirmed)) {
            return null;
        }

        $validator = Validator::make($data, [
            'did' => ['required', 'string'],
            'handle' => ['required', 'string'],
            'accessJwt' => ['sometimes', 'required', 'string'],
            'refreshJwt' => ['sometimes', 'required', 'string'],
        ]);

        if ($validator->fails()) {
            return null;
        }

        $data = $validator->validated();

        return new self(
            did: $data['did'],
            handle: $data['handle'],
            accessToken: data_get($data, 'accessJwt'),
            refreshToken: data_get($data, 'refreshJwt'),
            emailConfirmed: $emailConfirmed,
        );
    }

    public function hasTokens(): bool
    {
        return $this->accessToken !== null && $this->refreshToken !== null;
    }
}
