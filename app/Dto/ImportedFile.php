<?php

declare(strict_types=1);

namespace App\Dto;

use App\Models\Media;

final readonly class ImportedFile
{
    public const string UNREACHABLE = 'unreachable';

    public const string HOST_NOT_ALLOWED = 'host_not_allowed';

    public const string TYPE_NOT_ALLOWED = 'type_not_allowed';

    public const string TOO_LARGE = 'too_large';

    public function __construct(
        public ?Media $media,
        public ?string $failure,
    ) {}

    public static function stored(Media $media): self
    {
        return new self($media, null);
    }

    public static function failed(string $failure): self
    {
        return new self(null, $failure);
    }

    public function succeeded(): bool
    {
        return $this->media !== null;
    }
}
