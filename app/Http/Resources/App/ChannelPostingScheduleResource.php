<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelPostingScheduleResource extends JsonResource
{
    /**
     * @return array{timezone: string, posting_goal: int|null, posting_schedule: array<int, mixed>|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'timezone' => $this->timezone,
            'posting_goal' => $this->posting_goal,
            'posting_schedule' => $this->posting_schedule?->toArray(),
        ];
    }
}
