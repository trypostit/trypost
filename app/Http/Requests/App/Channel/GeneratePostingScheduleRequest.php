<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use App\Support\PostingSchedule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GeneratePostingScheduleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['goal', 'recommended'])],
            'goal' => ['nullable', 'integer', 'min:1', 'max:'.PostingSchedule::MAX_GOAL],
        ];
    }
}
