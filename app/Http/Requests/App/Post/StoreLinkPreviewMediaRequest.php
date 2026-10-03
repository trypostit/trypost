<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The page whose link preview image replaces the card in the composer. Only
 * the page URL is accepted; the server reads the image from the card itself.
 */
class StoreLinkPreviewMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createPost', $this->user()->currentWorkspace);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048'],
        ];
    }
}
