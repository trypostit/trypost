<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'payload' => $this->payload,
            'response_status' => $this->response_status,
            'response_body' => $this->response_body,
            'delivered_at' => $this->delivered_at?->toIso8601String(),
            'failed_at' => $this->failed_at?->toIso8601String(),
            'attempts' => $this->attempts,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
