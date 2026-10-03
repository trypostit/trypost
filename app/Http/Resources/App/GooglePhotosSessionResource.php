<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * A created session (`id`, `picker_uri`, `polling`) or a polled one
 * (`media_items_set`, `polling`); Google's `id` is exposed as `session_id`.
 */
class GooglePhotosSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $session = (array) $this->resource;

        return [
            ...(array_key_exists('id', $session) ? ['session_id' => data_get($session, 'id')] : []),
            ...Arr::except($session, 'id'),
        ];
    }
}
