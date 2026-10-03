<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Invite;

use App\Http\Requests\App\Invite\Concerns\ValidatesMemberAccess;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreWorkspaceInviteRequest extends FormRequest
{
    use ValidatesMemberAccess;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            ...$this->memberAccessRules(),
        ];
    }

    /**
     * Get custom messages for validation errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => __('validation.required', ['attribute' => 'email']),
            'email.email' => __('validation.email', ['attribute' => 'email']),
            'email.max' => __('validation.max.string', ['attribute' => 'email', 'max' => 255]),
        ];
    }
}
