<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Asset;

use Illuminate\Foundation\Http\FormRequest;

class ImportWebdavRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'paths' => ['required', 'array', 'min:1', 'max:20'],
            'paths.*' => ['required', 'string', 'max:1024'],
        ];
    }
}
