<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Http\Resources\App\MediaResource;
use App\Models\Media;
use Illuminate\Http\JsonResponse;

final readonly class ChunkReceipt
{
    private function __construct(
        public bool $done,
        public ?int $progress = null,
        public ?Media $media = null,
    ) {}

    public static function inProgress(int $progress): self
    {
        return new self(done: false, progress: $progress);
    }

    public static function completed(Media $media): self
    {
        return new self(done: true, media: $media);
    }

    public function toResponse(): JsonResponse
    {
        if (! $this->done) {
            return response()->json([
                'done' => false,
                'progress' => $this->progress,
            ]);
        }

        return response()->json([
            'done' => true,
            ...(new MediaResource($this->media))->resolve(),
        ]);
    }
}
