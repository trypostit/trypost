<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Whether the user's AI is connected to the current workspace yet, asked while
 * the MCP card's connect steps are open.
 */
class McpConnectionStatusResource extends JsonResource
{
    /**
     * @return array{connected: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'connected' => (bool) $this->resource,
        ];
    }
}
