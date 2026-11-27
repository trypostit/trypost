<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ImpersonateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * An admin views the app only as a user who is neither an admin, nor
     * blocked, nor themselves.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $target = $this->route('user');

                if (! $target instanceof User) {
                    return;
                }

                if ($target->is($this->user()) || $target->isBackofficeAdmin()) {
                    $validator->errors()->add('user', 'You cannot view the app as a backoffice admin.');

                    return;
                }

                if ($target->isBlocked()) {
                    $validator->errors()->add('user', 'Unblock this user before viewing the app as them.');
                }
            },
        ];
    }
}
