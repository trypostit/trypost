<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Media;

use App\Services\Media\Sources\WebdavFiles;
use Illuminate\Foundation\Http\FormRequest;

class BrowseWebdavRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createPost', $this->user()->currentWorkspace);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'path' => ['sometimes', 'nullable', 'string', 'max:'.WebdavFiles::MAX_PATH_LENGTH],
        ];
    }
}
