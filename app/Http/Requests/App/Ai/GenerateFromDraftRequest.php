<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Ai;

use Illuminate\Foundation\Http\FormRequest;

class GenerateFromDraftRequest extends FormRequest
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
        return [
            // The reviewed structure the user approved. Shape mirrors the
            // generator output (caption + slides, or content + image_* fields);
            // downstream assemble() reads keys defensively via data_get().
            'structured' => ['required', 'array'],
        ];
    }
}
