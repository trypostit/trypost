<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DestroyOtherSessionsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->user()->password) {
            return [
                'password' => ['required', 'string', 'current_password'],
            ];
        }

        return [
            'email_confirmation' => ['required', 'string', Rule::in([$this->user()->email])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email_confirmation.in' => __('settings.profile.sessions.email_mismatch'),
        ];
    }
}
