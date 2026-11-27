<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class BlockUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'reason' => [
                'required',
                'string',
                'max:500',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $target = $this->route('user');

                    if (! $target instanceof User) {
                        return;
                    }

                    if ($target->is($this->user()) || $target->isBackofficeAdmin()) {
                        $fail('A backoffice admin cannot be blocked.');
                    }
                },
            ],
        ];
    }
}
