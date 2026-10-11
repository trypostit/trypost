<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\User\PreviousSoftware;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWelcomeSoftwareRequest extends FormRequest
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
            'previous_software' => ['required', Rule::enum(PreviousSoftware::class)],
        ];
    }
}
