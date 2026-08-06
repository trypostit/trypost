<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Welcome;

use App\Enums\User\Goal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWelcomeGoalsRequest extends FormRequest
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
            'goals' => ['required', 'array', 'min:1'],
            'goals.*' => [Rule::enum(Goal::class)],
        ];
    }
}
