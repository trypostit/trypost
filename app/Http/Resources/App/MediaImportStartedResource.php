<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The imports one pick started, in the order their tiles are shown. A
 * Google Photos pick starts one per picked item; every other source one.
 */
class MediaImportStartedResource extends JsonResource
{
    /**
     * @return array{import_id: string, import_ids: list<string>}
     */
    public function toArray(Request $request): array
    {
        $importIds = array_values((array) $this->resource);

        return [
            'import_id' => $importIds[0],
            'import_ids' => $importIds,
        ];
    }
}
