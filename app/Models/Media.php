<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Media\Type as MediaType;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory, HasUuids;

    protected $table = 'medias';

    protected $appends = ['url'];

    protected $fillable = [
        'mediable_id',
        'mediable_type',
        'group_id',
        'collection',
        'type',
        'path',
        'original_filename',
        'mime_type',
        'size',
        'order',
        'meta',
        'upload_token',
    ];

    protected function casts(): array
    {
        return [
            'type' => MediaType::class,
            'size' => 'integer',
            'order' => 'integer',
            'meta' => 'array',
        ];
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn () => Storage::url($this->path),
        );
    }

    public function isVideo(): bool
    {
        return MediaType::classify($this->mime_type, $this->path) === MediaType::Video;
    }

    public function isImage(): bool
    {
        return MediaType::classify($this->mime_type, $this->path) === MediaType::Image;
    }

    public function isDocument(): bool
    {
        return MediaType::classify($this->mime_type, $this->path) === MediaType::Document;
    }

    /**
     * The item stored in `posts.media` for this asset. Carries `meta` so the
     * publish-time checks can read the measured duration.
     *
     * @return array<string, mixed>
     */
    public function toPostSnapshot(?string $alt = null): array
    {
        $meta = is_array($this->meta) ? $this->meta : [];

        if (filled($alt) && $this->isImage()) {
            $meta['alt_text'] = $alt;
        }

        $item = [
            'id' => $this->id,
            'path' => $this->path,
            'url' => $this->url,
            'type' => $this->type->value,
            'mime_type' => $this->mime_type,
            'original_filename' => $this->original_filename,
            'size' => $this->size,
        ];

        return $meta === [] ? $item : [...$item, 'meta' => $meta];
    }

    public function getTemporaryUrl(int $expirationMinutes = 60): string
    {
        return Storage::temporaryUrl(
            $this->path,
            now()->addMinutes($expirationMinutes)
        );
    }

    public function delete(): bool
    {
        // Only delete the file if no other media records use the same path
        $otherMediaWithSamePath = static::where('path', $this->path)
            ->where('id', '!=', $this->id)
            ->exists();

        if (! $otherMediaWithSamePath) {
            Storage::delete($this->path);
        }

        return parent::delete();
    }
}
