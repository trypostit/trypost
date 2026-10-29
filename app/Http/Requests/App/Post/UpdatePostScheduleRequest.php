<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Enums\Post\ScheduleAction;
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
        $notReschedule = Rule::excludeIf(fn (): bool => $this->enum('action', ScheduleAction::class) !== ScheduleAction::Reschedule);

        return [
            'action' => ['required', Rule::enum(ScheduleAction::class)],
            'scheduled_at' => [
                $notReschedule,
                'present',
                'nullable',
                'date',
                'after:now',
                'before:2038-01-19',
            ],
            'queue_slot' => [
                $notReschedule,
                'sometimes',
                'boolean',
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
            'scheduled_at.before' => __('calendar.reschedule.errors.too_far'),
        ];
    }
}
