<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InboxResolutionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        return ['resolved' => (int) $this->resource];
    }
}
