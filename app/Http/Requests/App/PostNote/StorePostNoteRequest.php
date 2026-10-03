<?php

declare(strict_types=1);

namespace App\Http\Requests\App\PostNote;

use Illuminate\Foundation\Http\FormRequest;

class StorePostNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:2000'],
        ];
    }
}
