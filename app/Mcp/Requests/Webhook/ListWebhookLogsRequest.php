<?php

declare(strict_types=1);

namespace App\Mcp\Requests\Webhook;

class ListWebhookLogsRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'webhook_id' => ['required', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
