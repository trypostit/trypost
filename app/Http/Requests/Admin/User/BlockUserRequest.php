<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BlockUserRequest extends FormRequest
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
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * An admin can block neither themselves nor another admin.
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
                    $validator->errors()->add('user', 'A backoffice admin cannot be blocked.');
                }
            },
        ];
    }
}
