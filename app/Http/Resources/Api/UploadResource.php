<?php

declare(strict_types=1);

namespace App\Http\Resources\Api;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A temporary upload from `POST /api/uploads`: pass `upload_token` as a media
 * item to a post call before `expires_at`.
 *
 * @mixin Media
 */
class UploadResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'upload_token' => $this->upload_token,
            'type' => $this->type,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'original_filename' => $this->original_filename,
            'url' => $this->url,
            'expires_at' => $this->created_at->copy()->addHours((int) config('trypost.media.upload_retention_hours'))->toIso8601String(),
        ];
    }
}
