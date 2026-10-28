<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Enums\Post\ScheduleAction;
use App\Enums\Post\Status as PostStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'action' => ['required', Rule::enum(ScheduleAction::class)],
            'scheduled_at' => [
                Rule::excludeIf(fn (): bool => $this->enum('action', ScheduleAction::class) !== ScheduleAction::Reschedule),
                'present',
                Rule::requiredIf(fn (): bool => $this->route('post')?->status !== PostStatus::Draft),
                'nullable',
                'date',
                'after:now',
                'before:2038-01-19',
            ],
        ];
    }

    /**
     * A post waiting for approval, or a scheduled one about to publish, keeps its time.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->enum('action', ScheduleAction::class) === ScheduleAction::Reschedule && ! $this->postIsMovable()) {
                    $validator->errors()->add('scheduled_at', __('calendar.reschedule.errors.not_movable'));
                }
            },
        ];
    }

    private function postIsMovable(): bool
    {
        $post = $this->route('post');

        return match ($post?->status) {
            PostStatus::PendingApproval => false,
            PostStatus::Scheduled => $post->scheduled_at?->isAfter(now()->addMinute()) ?? true,
            default => true,
        };
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
