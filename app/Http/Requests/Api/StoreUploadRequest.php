<?php

declare(strict_types=1);

namespace App\Http\Requests\Api;

use App\Enums\Media\Type as MediaType;
use App\Rules\HeicAccepted;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $allowed = implode(',', array_merge(
            MediaType::Image->allowedMimeTypes(),
            MediaType::Video->allowedMimeTypes(),
            MediaType::Document->allowedMimeTypes(),
        ));

        return [
            // Largest per-type cap as the FormRequest upper bound; per-type
            // enforcement runs in withValidator() below — same pattern as
            // StoreMediaRequest + PostController::storeMedia.
            'media' => [
                'bail',
                'required',
                'file',
                new HeicAccepted,
                'max:'.MediaType::Video->maxSizeInKb(),
                "mimetypes:{$allowed}",
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('media');

            if ($file === null || $validator->errors()->isNotEmpty()) {
                return;
            }

            $type = MediaType::fromMime((string) $file->getMimeType());

            if ($type === null) {
                $validator->errors()->add('media', 'This file type is not supported.');

                return;
            }

            if ($file->getSize() > $type->maxSizeInBytes()) {
                $validator->errors()->add(
                    'media',
                    "File size exceeds the maximum allowed for {$type->value} ({$type->maxSizeInMb()} MB).",
                );
            }
        });
    }
}
