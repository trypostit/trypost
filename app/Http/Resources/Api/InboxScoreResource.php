<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Actions\Inbox\CalculateInboxScore;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InboxScoreResource extends JsonResource
{
    /**
     * @return array{score: ?int, level: ?string, response_rate: ?int, response_speed: ?int, consistency: ?int, comments_count: int, answered_count: int, window_days: int, starts_at: string, ends_at: string, timezone: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'score' => data_get($this->resource, 'score'),
            'level' => data_get($this->resource, 'level'),
            'response_rate' => data_get($this->resource, 'response_rate'),
            'response_speed' => data_get($this->resource, 'response_speed'),
            'consistency' => data_get($this->resource, 'consistency'),
            'comments_count' => (int) data_get($this->resource, 'comments_count'),
            'answered_count' => (int) data_get($this->resource, 'answered_count'),
            'window_days' => CalculateInboxScore::WINDOW_DAYS,
            'starts_at' => data_get($this->resource, 'starts_at'),
            'ends_at' => data_get($this->resource, 'ends_at'),
            'timezone' => data_get($this->resource, 'timezone'),
        ];
    }
}
