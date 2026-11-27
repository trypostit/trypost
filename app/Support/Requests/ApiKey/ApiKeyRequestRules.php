<?php

declare(strict_types=1);

namespace App\Support\Requests\ApiKey;

/**
 * Single source of the API key rules shared by the web and API FormRequests.
 */
class ApiKeyRequestRules
{
    /**
     * @return array<string, mixed>
     */
    public static function store(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }
}
