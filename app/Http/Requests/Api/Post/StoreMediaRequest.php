<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Enums\Media\Type as MediaType;
use App\Rules\HeicAccepted;
use Illuminate\Foundation\Http\FormRequest;

class StoreMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $allowedMimes = [
            ...MediaType::Image->allowedMimeTypes(),
            ...MediaType::Video->allowedMimeTypes(),
            ...MediaType::Document->allowedMimeTypes(),
        ];

        return [
            // Use the largest per-type cap as the upper bound; per-type
            // and per-post enforcement happens in the controller.
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
}
