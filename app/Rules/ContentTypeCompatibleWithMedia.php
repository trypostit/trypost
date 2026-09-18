<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Number;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\ValidationException;

class ContentTypeCompatibleWithMedia implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @param  array<int, array<string, mixed>>|null  $fallbackMedia  Stored media used
     *                                                                when the request omits the `media` key entirely — lets API/MCP partial
     *                                                                updates (which don't resubmit media) validate a content_type against the
     *                                                                post's already-stored media.
     */
    public function __construct(private ?array $fallbackMedia = null) {}

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
        );

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The per-platform entries to validate for a post update: each platform's
     * effective content_type (resubmitted in this request, else its stored
     * value), keyed by the error path the caller surfaces. When $requestPlatforms
     * is null, the post's currently-enabled platforms are used.
     *
     * @param  array<int, mixed>|null  $requestPlatforms
     * @return array<int, array{key: string, content_type: string|null}>
     */
    public static function entriesForUpdate(Post $post, ?array $requestPlatforms): array
    {
        if (is_array($requestPlatforms)) {
            $stored = $post->postPlatforms()->get()->keyBy('id');

            return collect($requestPlatforms)->map(fn ($platform, $index): array => [
                'key' => "platforms.{$index}.content_type",
                'content_type' => data_get($platform, 'content_type')
                    ?? $stored->get(data_get($platform, 'id'))?->content_type?->value,
            ])->all();
        }

        return $post->postPlatforms()->enabled()->get()->values()
            ->map(fn ($postPlatform, $index): array => [
                'key' => "platforms.{$index}.content_type",
                'content_type' => $postPlatform->content_type?->value,
            ])->all();
    }

    /**
     * Validate a set of platform entries against the given media, returning
     * `[errorKey => message]` for each incompatible content_type.
     *
     * @param  array<int, array{key: string, content_type: string|null}>  $entries
     * @param  array<int, mixed>  $media
     * @return array<string, string>
     */
    public static function errorsFor(array $entries, array $media): array
    {
        $errors = [];
        $rule = new self($media);

        foreach ($entries as ['key' => $key, 'content_type' => $contentType]) {
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
            'no_video_allowed' => $hasVideo && ! $contentType->supportsVideo(),
            'no_image_allowed' => $hasImage && ! $contentType->supportsImage(),
            'no_document_allowed' => $hasDocument && ! $contentType->supportsDocument(),
            'no_mixed_media' => $hasImage && $hasVideo && ! $contentType->supportsMixedMedia(),
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
