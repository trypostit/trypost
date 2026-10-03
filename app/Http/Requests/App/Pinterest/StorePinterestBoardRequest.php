<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Pinterest;

use Illuminate\Foundation\Http\FormRequest;

class StorePinterestBoardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Pinterest caps board names at 50 characters.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
