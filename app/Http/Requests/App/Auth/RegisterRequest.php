<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Auth;

use App\Enums\User\Locale;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use App\Models\Invite;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', Rules\Password::defaults()],
            'locale' => ['required', Rule::enum(Locale::class)],
            'timezone' => ['nullable', 'string'],
            'week_starts_on' => ['nullable', Rule::enum(WeekStart::class)],
            'time_format' => ['nullable', Rule::enum(TimeFormat::class)],
        ];
    }

    public function invite(): ?Invite
    {
        return Invite::fromId($this->string('invite')->toString());
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $invite = $this->invite();

            if ($invite && $invite->email !== $this->input('email')) {
                $validator->errors()->add(
                    'email',
                    __('settings.members.flash.wrong_email'),
                );
            }
        });
    }
}
