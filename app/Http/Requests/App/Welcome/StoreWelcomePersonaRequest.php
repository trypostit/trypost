<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\User\Persona;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWelcomePersonaRequest extends FormRequest
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
            'persona' => ['required', Rule::enum(Persona::class)],
        ];
    }
}
