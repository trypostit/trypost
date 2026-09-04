<?php

declare(strict_types=1);

namespace App\Mcp\Requests\Webhook;

use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use Illuminate\Validation\Rule;

class UpdateWebhookRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'webhook_id' => ['required', 'string'],
            'endpoint' => ['sometimes', 'url', 'max:255'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::enum(EventType::class)],
            'status' => ['sometimes', 'string', Rule::enum(Status::class)->only([Status::Enabled, Status::Disabled])],
        ];
    }
}
