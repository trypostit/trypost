<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
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
            return collect($requestPlatforms)->map(fn ($platform, $index): array => [
                'key' => "platforms.{$index}.content_type",
                'content_type' => data_get($platform, 'content_type')
                    ?? $post->postPlatforms()->where('id', data_get($platform, 'id'))->first()?->content_type?->value,
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

        foreach ($entries as $entry) {
            $contentType = data_get($entry, 'content_type');

            if ($contentType === null) {
                continue;
            }

            (new self($media))->validate(
                $entry['key'],
                (string) $contentType,
                function (string $message) use (&$errors, $entry): void {
                    $errors[$entry['key']] = $message;
                },
            );
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

        // Use the request's media when present; otherwise fall back to the
        // post's stored media so partial publish/schedule updates still validate.
        $media = array_key_exists('media', $this->data)
            ? (array) data_get($this->data, 'media', [])
            : (array) ($this->fallbackMedia ?? []);
        $count = count($media);

        if ($contentType->requiresMedia() && $count === 0) {
            $fail("{$contentType->label()} requires at least one media file.");

            return;
        }

        if ($count === 0) {
            return;
        }

        $maxFiles = $contentType->maxMediaCount();
        if ($count > $maxFiles) {
            $fail("{$contentType->label()} allows at most {$maxFiles} media file(s).");

            return;
        }

        $minFiles = $contentType->minMediaCount();
        if ($minFiles > 0 && $count < $minFiles) {
            $fail("{$contentType->label()} requires at least {$minFiles} media files.");

            return;
        }

        $hasImage = collect($media)->contains(fn ($item) => $this->isImage((array) $item));
        $hasVideo = collect($media)->contains(fn ($item) => $this->isVideo((array) $item));
        $hasDocument = collect($media)->contains(fn ($item) => $this->isDocument((array) $item));
        $hasGif = collect($media)->contains(fn ($item) => MediaType::isGif(data_get((array) $item, 'mime_type')));

        if ($hasImage && ! $contentType->supportsImage()) {
            $fail("{$contentType->label()} does not support images.");
        }

        if ($hasVideo && ! $contentType->supportsVideo()) {
            $fail("{$contentType->label()} does not support videos.");
        }

        if ($hasDocument && ! $contentType->supportsDocument()) {
            $fail("{$contentType->label()} does not support PDF documents.");
        }

        if ($hasGif && ! $contentType->acceptsGif()) {
            $fail("{$contentType->label()} does not support animated GIFs.");
        }

        // A PDF document is always published on its own (LinkedIn document post).
        if ($hasDocument && $count > 1) {
            $fail('A PDF document must be the only attachment.');
        }

        if ($hasImage && $hasVideo && ! $contentType->supportsMixedMedia()) {
            $fail("{$contentType->label()} can't combine an image and a video in the same post.");
        }

        foreach ($media as $item) {
            $this->validateItemConstraints($contentType, (array) $item, $fail);
        }
    }

    /**
     * Per-item size, duration, and aspect-ratio checks against the content
     * type's numeric rules (the same rules `ContentType::mediaRules()` gives
     * the Vue editor via `useMediaRules`/`useMedia`). Duration and dimensions
     * come from client-supplied `meta` - there is no server-side probe (e.g.
     * ffprobe) yet, so a missing value is treated as unknown and skipped
     * rather than rejected.
     *
     * @param  array<string, mixed>  $item
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    private function validateItemConstraints(ContentType $contentType, array $item, Closure $fail): void
    {
        $size = (int) data_get($item, 'size', 0);

        if ($this->isDocument($item)) {
            $maxDocumentBytes = $contentType->maxDocumentBytes();

            if ($maxDocumentBytes && $size > $maxDocumentBytes) {
                $fail("{$contentType->label()} documents must be under ".self::formatBytes($maxDocumentBytes).'.');
            }

            return;
        }

        if ($this->isVideo($item)) {
            $maxVideoBytes = $contentType->maxVideoBytes();

            if ($maxVideoBytes && $size > $maxVideoBytes) {
                $fail("{$contentType->label()} videos must be under ".self::formatBytes($maxVideoBytes).'.');
            }

            $maxDuration = $contentType->maxVideoDurationSec();
            $duration = (float) data_get($item, 'meta.duration', 0);

            if ($maxDuration && $duration > $maxDuration) {
                $fail("{$contentType->label()} videos must be under ".self::formatDuration($maxDuration).'.');
            }
        } elseif ($this->isImage($item)) {
            $maxImageBytes = $contentType->maxImageBytes();

            if ($maxImageBytes && $size > $maxImageBytes) {
                $fail("{$contentType->label()} images must be under ".self::formatBytes($maxImageBytes).'.');
            }
        }

        $this->validateAspectRatio($contentType, $item, $fail);
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    private function validateAspectRatio(ContentType $contentType, array $item, Closure $fail): void
    {
        $width = (float) data_get($item, 'meta.width', 0);
        $height = (float) data_get($item, 'meta.height', 0);

        if ($width <= 0 || $height <= 0) {
            return;
        }

        if ($contentType->autoFitsImage() && $this->isImage($item)) {
            return;
        }

        $bounds = $contentType->aspectRatioBounds();

        if (! $bounds) {
            return;
        }

        $ratio = $width / $height;

        if ($ratio < $bounds['min'] || $ratio > $bounds['max']) {
            $fail("{$contentType->label()} media must have an aspect ratio between {$bounds['min']} and {$bounds['max']}.");
        }
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 1).' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        return number_format($bytes / 1024, 1).' KB';
    }

    private static function formatDuration(int $seconds): string
    {
        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes === 0) {
            return "{$seconds}s";
        }

        return $remainingSeconds === 0 ? "{$minutes}m" : "{$minutes}m {$remainingSeconds}s";
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isImage(array $item): bool
    {
        return $this->isType($item, MediaType::Image);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isVideo(array $item): bool
    {
        return $this->isType($item, MediaType::Video);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isDocument(array $item): bool
    {
        return $this->isType($item, MediaType::Document);
    }

    /**
     * A media item matches a type when it carries that explicit `type`, or when
     * its MIME classifies as that type.
     *
     * @param  array<string, mixed>  $item
     */
    private function isType(array $item, MediaType $type): bool
    {
        return data_get($item, 'type') === $type->value
            || MediaType::classify(data_get($item, 'mime_type')) === $type;
    }
}
