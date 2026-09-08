<?php

declare(strict_types=1);

namespace App\Mcp\Requests\Webhook;

use App\Enums\Webhook\EventType;
use Illuminate\Validation\Rule;

class CreateWebhookRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::enum(EventType::class)],
        ];
    }
}
