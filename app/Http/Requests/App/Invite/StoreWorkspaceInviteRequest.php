<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Invite;

use App\Enums\UserWorkspace\Role as WorkspaceRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Email;

class StoreWorkspaceInviteRequest extends FormRequest
{
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
            'email' => ['required', Email::default(), 'max:255'],
            'role' => ['required', Rule::in(array_column(WorkspaceRole::cases(), 'value'))],
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
