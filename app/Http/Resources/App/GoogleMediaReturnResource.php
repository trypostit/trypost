<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Enums\Media\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A finished Google sign-in: the Drive token the Picker needs, or the
 * Photos picker session (its token stays on the server).
 */
class GoogleMediaReturnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $source = data_get($this->resource, 'source');

        return $source === Source::GoogleDrive->value
            ? [
                'source' => $source,
                'access_token' => data_get($this->resource, 'access_token'),
                'expires_in' => data_get($this->resource, 'expires_in'),
            ]
            : [
                'source' => $source,
                'session_id' => data_get($this->resource, 'session_id'),
                'polling' => data_get($this->resource, 'polling'),
            ];
    }
}
