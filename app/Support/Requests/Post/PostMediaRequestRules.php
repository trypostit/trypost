<?php

declare(strict_types=1);

namespace App\Support\Requests\Post;

use App\Enums\Media\Type as MediaType;
use App\Models\Post;
use App\Rules\HeicAccepted;
use App\Support\PostMediaRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Single source of the media attach and upload rules shared by the public API
 * FormRequests and the MCP media tools. Every input is explicit, so the rules
 * never depend on the current request or authenticated user.
 */
class PostMediaRequestRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function attachFromUpload(): array
    {
        return [
            'upload_token' => ['required', 'uuid'],
            'alt' => ['nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public static function attachFromUrl(): array
    {
        return [
            'urls' => ['required', 'array', 'min:1', 'max:10'],
            'urls.*.url' => ['required', 'url:http,https', 'active_url'],
            'urls.*.alt' => ['nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH],
        ];
    }

    /**
     * @return array<string, array<int, ValidationRule|string>>
     */
    public static function file(): array
    {
        $allowedMimes = [
            ...MediaType::Image->allowedMimeTypes(),
            ...MediaType::Video->allowedMimeTypes(),
            ...MediaType::Document->allowedMimeTypes(),
        ];

        return [
            'media' => [
                'bail',
                'required',
                'file',
                new HeicAccepted,
                'max:'.MediaType::Video->maxSizeInKb(),
                'mimetypes:'.implode(',', $allowedMimes),
            ],
        ];
    }

    public static function fileViolation(?UploadedFile $file): ?string
    {
        if ($file === null) {
            return null;
        }

        $type = MediaType::fromMime((string) $file->getMimeType());

        if ($type === null) {
            return __('posts.errors.media_file_unsupported');
        }

        if ($file->getSize() > $type->maxSizeInBytes()) {
            return __('posts.errors.media_file_too_large', ['size' => $type->maxSizeInMb()]);
        }

        return null;
    }

    public static function typeViolation(Post $post, ?MediaType $type): ?string
    {
        if ($type === null || ! in_array($type, $post->allowedMediaTypes(), true)) {
            return __('posts.errors.media_type_unsupported');
        }

        return null;
    }
}
