<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Whether the user may go on to create another workspace, or must see the
 * upgrade dialog first.
 */
class WorkspaceCreationCheckResource extends JsonResource
{
    /**
     * @return array{allowed: bool}
     */
    public function toArray(Request $request): array
    {
        return [
            'allowed' => (bool) $this->resource,
        ];
    }
}
