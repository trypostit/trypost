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

        $hasImage = collect($media)->contains(fn ($item) => $this->isImage((array) $item));
        $hasVideo = collect($media)->contains(fn ($item) => $this->isVideo((array) $item));
        $hasDocument = collect($media)->contains(fn ($item) => $this->isDocument((array) $item));

        if ($hasImage && ! $contentType->supportsImage()) {
            $fail("{$contentType->label()} does not support images.");
        }

        if ($hasVideo && ! $contentType->supportsVideo()) {
            $fail("{$contentType->label()} does not support videos.");
        }

        if ($hasDocument && ! $contentType->supportsDocument()) {
            $fail("{$contentType->label()} does not support PDF documents.");
        }

        // A PDF document is always published on its own (LinkedIn document post).
        if ($hasDocument && $count > 1) {
            $fail('A PDF document must be the only attachment.');
        }

        if ($hasImage && $hasVideo && ! $contentType->supportsMixedMedia()) {
            $fail("{$contentType->label()} can't combine an image and a video in the same post.");
        }

        if (! $contentType->acceptsGif() && collect($media)->contains(fn ($item) => MediaType::isGif(data_get($item, 'mime_type')))) {
            $fail("{$contentType->label()} does not accept GIF. Use a still image or choose a different network.");
        }

        if (! $contentType->acceptsMov() && collect($media)->contains(fn ($item) => MediaType::isMov(
            data_get($item, 'mime_type'),
            data_get($item, 'original_filename') ?? data_get($item, 'path'),
        ))) {
            $fail("{$contentType->label()} does not accept MOV videos. Use MP4.");
        }

        $this->failOnSizeAndDurationCaps($contentType, $media, $fail);
    }

    /**
     * Server-side mirror of the editor's per-item size / duration checks, so the
     * REST API and MCP cannot store media the network will reject at publish
     * (the ~1 GB Instagram Reel that motivated this: Meta's cap is 300 MB).
     *
     * Best-effort by design: `size` is present on every snapshot we write, but
     * video `meta.duration` is measured client-side and only exists when the
     * dashboard uploaded the file — an item without it is not checked.
     *
     * @param  array<int, mixed>  $media
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    private function failOnSizeAndDurationCaps(ContentType $contentType, array $media, Closure $fail): void
    {
        $label = $contentType->label();

        foreach ($media as $item) {
            $item = (array) $item;
            $size = (int) data_get($item, 'size', 0);

            if ($size > 0) {
                [$kind, $max] = match (true) {
                    $this->isDocument($item) => ['a PDF', $contentType->maxDocumentBytes()],
                    $this->isVideo($item) => ['a video', $contentType->maxVideoBytes()],
                    default => ['an image', $contentType->maxImageBytes()],
                };

                if ($max !== null && $size > $max) {
                    $fail("{$label} accepts {$kind} of up to ".Number::fileSize($max).' (yours is '.Number::fileSize($size, 1).').');

                    return;
                }
            }

            $duration = data_get($item, 'meta.duration');
            $maxDuration = $contentType->maxVideoDurationSec();

            if ($maxDuration !== null && is_numeric($duration) && $this->isVideo($item) && (float) $duration > $maxDuration) {
                $fail("{$label} accepts videos of up to ".$this->formatDuration($maxDuration).' (yours is '.$this->formatDuration((int) ceil((float) $duration)).').');

                return;
            }
        }
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds}s";
        }

        $minutes = intdiv($seconds, 60);
        $rest = $seconds % 60;

        return $rest === 0 ? "{$minutes} min" : "{$minutes} min {$rest}s";
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
