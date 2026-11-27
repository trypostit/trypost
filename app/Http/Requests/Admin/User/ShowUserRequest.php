<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\User;

use App\Enums\Admin\UserTab;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowUserRequest extends FormRequest
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
            'tab' => ['nullable', Rule::enum(UserTab::class)],
        ];
    }

    public function tab(): UserTab
    {
        return UserTab::tryFrom((string) $this->validated('tab')) ?? UserTab::Overview;
    }
}
