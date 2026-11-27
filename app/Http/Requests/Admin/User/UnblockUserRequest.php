<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use Illuminate\Foundation\Http\FormRequest;

class UnblockUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Unblocking takes no input: the user comes from the route.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [];
    }
}
