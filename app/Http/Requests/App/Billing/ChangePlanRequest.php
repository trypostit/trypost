<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Billing;

use App\Enums\Billing\Interval;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAccountOwner();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'plan_id' => [
                'required',
                'uuid',
                Rule::exists('plans', 'id')->where(fn ($query) => $query->where('is_archived', false)),
            ],
            'interval' => ['required', Rule::enum(Interval::class)],
        ];
    }
}
