<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdatePasswordRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = [
            'password' => ['required', 'confirmed', Password::defaults()],
        ];

        if ($this->user()->password) {
            $rules['current_password'] = ['required', 'string', 'current_password'];
        }

        return $rules;
    }
}
