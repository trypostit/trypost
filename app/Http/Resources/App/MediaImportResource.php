<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MediaImportResource extends JsonResource
{
    /**
     * @param  array{status: string, reason: ?string, replaces: ?string}  $entry
     */
    public function __construct(array $entry, private readonly ?Media $media)
    {
        parent::__construct($entry);
    }

    /**
     * @return array{status: string, media: ?MediaResource, reason: ?string, replaces: ?string}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => data_get($this->resource, 'status'),
            'media' => $this->media === null ? null : new MediaResource($this->media),
            'reason' => data_get($this->resource, 'reason'),
            'replaces' => data_get($this->resource, 'replaces'),
        ];
    }
}
