<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Enums\Admin\UserStatusFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IndexUserRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(UserStatusFilter::class)],
        ];
    }

    public function search(): ?string
    {
        return $this->validated('search');
    }

    public function status(): UserStatusFilter
    {
        return UserStatusFilter::tryFrom((string) $this->validated('status')) ?? UserStatusFilter::All;
    }
}
