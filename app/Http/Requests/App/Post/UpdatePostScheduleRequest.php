<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Enums\Post\Status as PostStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['draft', 'publish_now', 'queue_next', 'queue_top', 'reschedule'])],
            'scheduled_at' => [
                'exclude_unless:action,reschedule',
                'present',
                Rule::requiredIf(fn (): bool => $this->route('post')?->status !== PostStatus::Draft),
                'nullable',
                'date',
                'after:now',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_at.after' => __('calendar.reschedule.errors.past'),
            'scheduled_at.required' => __('calendar.reschedule.errors.required'),
        ];
    }
}
