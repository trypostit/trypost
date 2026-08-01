<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Auth;

use App\Models\Invite;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
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
        ];
    }

    /**
     * A valid invite binds registration to the invited email — never a
     * different one. Non-UUID invite params are self-hosted gate placeholders
     * and are ignored.
     */
    public function invite(): ?Invite
    {
        $inviteId = (string) $this->input('invite', '');

        if ($inviteId === '' || ! Str::isUuid($inviteId)) {
            return null;
        }

        return Invite::query()->find($inviteId);
    }

    public function isInviteRegistration(): bool
    {
        return $this->invite() !== null;
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
