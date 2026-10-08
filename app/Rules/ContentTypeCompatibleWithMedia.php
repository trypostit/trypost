<?php

declare(strict_types=1);

namespace App\Rules;

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Media\ImageDimensions;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\ValidationException;

class ContentTypeCompatibleWithMedia implements DataAwareRule, ValidationRule
{
    /**
     * Float slack on ratio bounds, like `RATIO_TOLERANCE` in useMedia.ts.
     */
    private const float RATIO_TOLERANCE = 0.0001;

    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @var array{key: string, rows: Collection<string, Media>}|null
     */
    private ?array $resolvedRows = null;

    /**
     * @param  array<int, array<string, mixed>>|null  $fallbackMedia  Stored media used
     *                                                                when the request omits the `media` key entirely — lets API/MCP partial
     *                                                                updates (which don't resubmit media) validate a content_type against the
     *                                                                post's already-stored media.
     * @param  Workspace|null  $workspace  Where the items' `medias` rows live; the
     *                                     aspect-ratio and pixel checks read the
     *                                     server-measured dimensions from them and
     *                                     are skipped without it.
     */
    public function __construct(private ?array $fallbackMedia = null, private ?Workspace $workspace = null) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * Validate every enabled platform's stored content_type against the post's
     * stored media. Used by publish flows that don't resubmit media (e.g. the
     * MCP publish tool) — the media-side mirror of
     * PostPlatformMetaRules::assertStoredPostPublishable().
     *
     * @throws ValidationException
     */
    public static function assertStoredPostCompatible(Post $post): void
    {
        $errors = self::errorsFor(
            self::entriesForUpdate($post, null),
            (array) ($post->media ?? []),
            $post->workspace,
        );

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The content type an update would publish: the submitted one, else the
     * one the new media derives (Pinterest, TikTok), else the stored one.
     *
     * @param  array<int, mixed>|null  $media
     * @return array<int, array{key: string, content_type: string|null}>
     */
    public static function entriesForUpdate(Post $post, ?string $contentType, ?array $media = null): array
    {
        if (! $post->hasDestination()) {
            return [];
        }

        return [[
            'key' => 'content_type',
            'content_type' => $contentType ?? ($media !== null && ContentType::derivesFromMedia($post->platform)
                ? ContentType::forMedia($post->platform, $media)->value
                : $post->content_type?->value),
        ]];
    }

    /**
     * Validate a set of platform entries against the given media, returning
     * `[errorKey => message]` for each incompatible content_type.
     *
     * @param  array<int, array{key: string, content_type: string|null}>  $entries
     * @param  array<int, mixed>  $media
     * @return array<string, string>
     */
    public static function errorsFor(array $entries, array $media, ?Workspace $workspace = null): array
    {
        $errors = [];
        $rule = new self($media, $workspace);

        foreach ($entries as $entry) {
            ['key' => $key, 'content_type' => $contentType] = $entry;

            if ($contentType === null) {
                continue;
            }

            $rule->validate($key, $contentType, function (string $message) use (&$errors, $key): void {
                $errors[$key] = $message;
            });
        }

        return $errors;
    }

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $contentType = ContentType::tryFrom((string) $value);

        if (! $contentType) {
            return;
        }

        $media = $this->media();

        if ($contentType->maxMediaCount() === 0) {
            if ($media !== []) {
                $fail(trans('posts.form.warnings.text_only'));
            }

            return;
        }

        if ($media === []) {
            if ($contentType->requiresMedia()) {
                $fail(trans('posts.form.warnings.requires_media'));
            }

            return;
        }

        // One message per platform, like `firstWarning` in useMedia.ts: a kind violation
        // ("does not accept GIF") is the root cause, so a size violation on the same
        // item must not overwrite it in errorsFor().
        if ($this->failOnKindRules($contentType, $media, $fail)) {
            return;
        }

        if (count($media) > $contentType->maxMediaCount()) {
            $fail(trans('posts.form.warnings.max_files_exceeded', ['max' => $contentType->maxMediaCount(), 'current' => count($media)]));

            return;
        }

        if ($this->failOnDimensionRules($contentType, $media, $fail)) {
            return;
        }

        $this->failOnSizeAndDurationCaps($contentType, $media, $fail);
    }

    /**
     * Request `media` when the key is present (including an empty list);
     * otherwise the stored fallback so a partial publish still validates.
     *
     * @return array<int, array<string, mixed>>
     */
    private function media(): array
    {
        return collect(data_get($this->data, 'media', $this->fallbackMedia))
            ->map(fn (mixed $item): array => (array) $item)
            ->all();
    }

    /**
     * Reports the first kind violation, in the editor's priority order.
     *
     * @param  array<int, array<string, mixed>>  $media
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     * @return bool Whether a violation was reported.
     */
    private function failOnKindRules(ContentType $contentType, array $media, Closure $fail): bool
    {
        $items = collect($media);
        $types = $items->map($this->typeOf(...));
        $hasImage = $types->contains(MediaType::Image);
        $hasVideo = $types->contains(MediaType::Video);
        $hasDocument = $types->contains(MediaType::Document);

        $violations = [
            'no_mixed_media' => $hasImage && $hasVideo && ! $contentType->supportsMixedMedia(),
            'no_video_allowed' => $hasVideo && ! $contentType->supportsVideo(),
            'no_image_allowed' => $hasImage && ! $contentType->supportsImage(),
            'no_document_allowed' => $hasDocument && ! $contentType->supportsDocument(),
            'document_not_alone' => $hasDocument && $items->count() > 1,
            'gif_not_allowed' => $items->contains($this->isGif(...)) && ! $contentType->acceptsGif(),
            'mov_not_allowed' => $items->contains($this->isMov(...)) && ! $contentType->acceptsMov(),
        ];

        $key = array_find_key($violations, fn (bool $failed): bool => $failed);

        if ($key === null) {
            return false;
        }

        $fail(trans("posts.form.warnings.{$key}"));

        return true;
    }

    /**
     * Aspect ratio and pixel size (spec ME20). The kind and dimensions come from
     * the item's `medias` row, never from the request; an image row without
     * dimensions is measured once from the stored file (EXIF orientation
     * applied) and written back. Still images the publisher fits into the frame
     * (autoFitsImage) are not checked. The server measures images only: a video's width / height are the
     * ones its upload declared, so videos are checked
     * against the ratio only when their row carries them.
     *
     * @param  array<int, array<string, mixed>>  $media
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     * @return bool Whether a violation was reported.
     */
    public function failOnDimensionRules(ContentType $contentType, array $media, Closure $fail): bool
    {
        $hasRatioBounds = $contentType->aspectRatioBounds(MediaType::Image) !== null
            || $contentType->aspectRatioBounds(MediaType::Video) !== null;

        if ((! $hasRatioBounds && $contentType->imageDimensionBounds() === null) || $this->workspace === null) {
            return false;
        }

        $rows = $this->rowsFor($media);

        foreach ($media as $item) {
            $row = $this->rowOf($item, $rows);

            if ($row === null) {
                continue;
            }

            $type = $row->type ?? $this->typeOf($item);
            $isImage = $type === MediaType::Image;

            if (! $isImage && $type !== MediaType::Video) {
                continue;
            }

            if ($isImage && ($contentType->autoFitsImage())) {
                continue;
            }

            $dimensions = $this->dimensionsOf($row, $isImage);

            if ($dimensions === null) {
                continue;
            }

            $ratioBounds = $contentType->aspectRatioBounds($type, MediaType::isGif($row->mime_type));
            $message = $this->dimensionViolation($contentType, $dimensions, $isImage, $ratioBounds);

            if ($message !== null) {
                $fail($message);

                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{width: int, height: int}  $dimensions
     * @param  array{min: float, max: float}|null  $ratioBounds
     */
    private function dimensionViolation(ContentType $contentType, array $dimensions, bool $isImage, ?array $ratioBounds): ?string
    {
        ['width' => $width, 'height' => $height] = $dimensions;
        $ratio = $width / $height;
        $destination = $contentType->destinationLabel();

        if ($ratioBounds !== null && $ratio < $ratioBounds['min'] - self::RATIO_TOLERANCE) {
            return trans('posts.form.warnings.aspect_ratio_too_narrow', [
                'destination' => $destination,
                'current' => $this->formatAspect($ratio),
                'min' => $this->formatAspect($ratioBounds['min']),
            ]);
        }

        if ($ratioBounds !== null && $ratio > $ratioBounds['max'] + self::RATIO_TOLERANCE) {
            return trans('posts.form.warnings.aspect_ratio_too_wide', [
                'destination' => $destination,
                'current' => $this->formatAspect($ratio),
                'max' => $this->formatAspect($ratioBounds['max']),
            ]);
        }

        $pixelBounds = $contentType->imageDimensionBounds();

        if (! $isImage || $pixelBounds === null) {
            return null;
        }

        $current = "{$width}×{$height}";

        if ($width < ($pixelBounds['min_width'] ?? 0) || $height < ($pixelBounds['min_height'] ?? 0)) {
            return trans('posts.form.warnings.image_too_small_dimensions', [
                'destination' => $destination,
                'current' => $current,
                'min' => "{$pixelBounds['min_width']}×{$pixelBounds['min_height']}",
            ]);
        }

        $maxWidth = $pixelBounds['max_width'] ?? PHP_INT_MAX;
        $maxHeight = $pixelBounds['max_height'] ?? PHP_INT_MAX;

        if ($width > $maxWidth || $height > $maxHeight) {
            return trans('posts.form.warnings.image_too_large_dimensions', [
                'destination' => $destination,
                'current' => $current,
                'max' => "{$pixelBounds['max_width']}×{$pixelBounds['max_height']}",
            ]);
        }

        return null;
    }

    /**
     * Mirrors `formatAspect` in useMedia.ts.
     */
    private function formatAspect(float $ratio): string
    {
        return number_format($ratio, 2, '.', '');
    }

    /**
     * The workspace rows behind the items, keyed `token:<upload_token>` and
     * `id:<id>`. Resolved once per media list: errorsFor() validates the same
     * list for every platform.
     *
     * @param  array<int, array<string, mixed>>  $media
     * @return Collection<string, Media>
     */
    private function rowsFor(array $media): Collection
    {
        $items = collect($media);
        $tokens = $items->pluck('upload_token')->filter(fn (mixed $token): bool => is_string($token) && $token !== '')->values()->all();
        $ids = $items->pluck('id')->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))->values()->all();
        $key = implode('|', [...$tokens, '#', ...$ids]);

        if (data_get($this->resolvedRows, 'key') === $key) {
            return $this->resolvedRows['rows'];
        }

        $rows = ResolveWorkspaceMedia::byUploadTokens($this->workspace, $tokens)
            ->toBase()
            ->mapWithKeys(fn (Media $row, string $token): array => ["token:{$token}" => $row])
            ->merge(ResolveWorkspaceMedia::execute($this->workspace, $ids)
                ->toBase()
                ->mapWithKeys(fn (Media $row, string $id): array => ["id:{$id}" => $row]));

        $this->resolvedRows = ['key' => $key, 'rows' => $rows];

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<string, Media>  $rows
     */
    private function rowOf(array $item, Collection $rows): ?Media
    {
        $token = data_get($item, 'upload_token');

        return filled($token)
            ? $rows->get("token:{$token}")
            : $rows->get('id:'.data_get($item, 'id'));
    }

    /**
     * @return array{width: int, height: int}|null
     */
    private function dimensionsOf(Media $row, bool $isImage): ?array
    {
        $width = (int) data_get($row->meta, 'width', 0);
        $height = (int) data_get($row->meta, 'height', 0);

        if ($width > 0 && $height > 0) {
            return ['width' => $width, 'height' => $height];
        }

        if (! $isImage) {
            return null;
        }

        $measured = rescue(fn (): ?array => ImageDimensions::fromBytes((string) Storage::get($row->path)), null, report: false);

        if ($measured === null) {
            Log::warning('Media dimensions unreadable; skipping the aspect-ratio and size check', [
                'media_id' => $row->id,
                'path' => $row->path,
            ]);

            return null;
        }

        $row->forceFill(['meta' => [...($row->meta ?? []), ...$measured]])->saveQuietly();

        return $measured;
    }

    /**
     * Server-side mirror of the editor's size / duration checks, so API and MCP
     * clients get the same early warning the editor shows. `size` and
     * `meta.duration` are the item's own values (written by the server on upload,
     * resubmitted by the client); an item without them is not checked. This is a
     * courtesy check, not a security boundary — the network enforces its own caps.
     *
     * @param  array<int, array<string, mixed>>  $media
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    private function failOnSizeAndDurationCaps(ContentType $contentType, array $media, Closure $fail): void
    {
        $maxDuration = $contentType->maxVideoDurationSec();

        foreach ($media as $item) {
            $size = (int) data_get($item, 'size', 0);
            [$key, $max] = $this->byteCap($contentType, $item);

            if ($max !== null && $size > $max) {
                $fail(trans("posts.form.warnings.{$key}", [
                    'max' => $this->formatBytes($max, $max),
                    'current' => $this->formatBytes($size, $max, 1),
                ]));

                return;
            }

            $duration = data_get($item, 'meta.duration');

            if ($maxDuration !== null && is_numeric($duration) && $duration > $maxDuration && $this->typeOf($item) === MediaType::Video) {
                $fail(trans('posts.form.warnings.video_too_long', [
                    'max' => $this->formatDuration($maxDuration),
                    'current' => $this->formatDuration((int) ceil((float) $duration)),
                ]));

                return;
            }
        }
    }

    /**
     * The cap an item is measured against; none when nothing identifies the item.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: string|null, 1: int|null}
     */
    private function byteCap(ContentType $contentType, array $item): array
    {
        return match ($this->typeOf($item)) {
            MediaType::Document => ['document_too_large', $contentType->maxDocumentBytes()],
            MediaType::Video => ['video_too_large', $contentType->maxVideoBytes()],
            MediaType::Image => ['image_too_large', $contentType->maxImageBytes()],
            null => [null, null],
        };
    }

    /**
     * Mirrors `formatBytes` in useMedia.ts: a cap declared in decimal megabytes
     * (Bluesky) renders both numbers in decimal units — "300 MB", not "286 MB".
     */
    private function formatBytes(int $bytes, int $cap, int $precision = 0): string
    {
        return $this->isDecimalCap($cap)
            ? $this->formatDecimalBytes($bytes, $precision)
            : Number::fileSize($bytes, $precision);
    }

    /**
     * A cap built with ContentType::bytesFromDecimalMb(): a whole number of
     * megabytes that is not also a whole number of mebibytes.
     */
    private function isDecimalCap(int $cap): bool
    {
        return $cap % 1_000_000 === 0 && $cap % (1024 * 1024) !== 0;
    }

    private function formatDecimalBytes(int $bytes, int $precision): string
    {
        if ($bytes < 1_000) {
            return "{$bytes} B";
        }

        [$divisor, $unit] = match (true) {
            $bytes >= 1_000_000_000 => [1_000_000_000, 'GB'],
            $bytes >= 1_000_000 => [1_000_000, 'MB'],
            default => [1_000, 'KB'],
        };

        $value = Number::format($bytes / $divisor, $precision);

        return "{$value} {$unit}";
    }

    /**
     * Mirrors `formatDurationWords` in date.ts: "45s", "5min", "5min 30s".
     */
    private function formatDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return match (true) {
            $minutes === 0 => "{$rest}s",
            $rest === 0 => "{$minutes}min",
            default => "{$minutes}min {$rest}s",
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isGif(array $item): bool
    {
        return MediaType::isGif(data_get($item, 'mime_type'));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isMov(array $item): bool
    {
        return MediaType::isMov(data_get($item, 'mime_type'), $this->fileNameOf($item));
    }

    /**
     * Mirrors `classify()` in mediaType.ts and `MediaItem::kind()`: the explicit
     * `type` wins, otherwise the item classifies by MIME, then by filename — so
     * an item without a MIME is still measured against the right cap instead of
     * the image one.
     *
     * @param  array<string, mixed>  $item
     */
    private function typeOf(array $item): ?MediaType
    {
        return MediaType::tryFrom((string) data_get($item, 'type', ''))
            ?? MediaType::classify(data_get($item, 'mime_type'), $this->fileNameOf($item));
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function fileNameOf(array $item): ?string
    {
        return data_get($item, 'original_filename') ?? data_get($item, 'path');
    }
}
