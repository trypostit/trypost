<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebhookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'endpoint' => $this->endpoint,
            'events' => $this->events,
            'status' => $this->status->value,
            'last_sent_at' => $this->last_sent_at?->toIso8601String(),
            'paused_at' => $this->paused_at?->toIso8601String(),
            'consecutive_failures' => $this->consecutive_failures,
            'signing_secret' => $this->when(
                ! in_array('signing_secret', $this->resource->getHidden(), true),
                $this->signing_secret,
            ),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
