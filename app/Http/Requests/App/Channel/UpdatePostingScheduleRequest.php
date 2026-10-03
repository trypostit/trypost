<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use App\Support\PostingSchedule;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdatePostingScheduleRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'timezone:all'],
            'posting_goal' => ['nullable', 'integer', 'min:1', 'max:'.PostingSchedule::MAX_GOAL],
            'posting_schedule' => ['nullable', 'array', 'size:7'],
            'posting_schedule.*.day' => ['required', 'integer', 'between:0,6', 'distinct'],
            'posting_schedule.*.enabled' => ['required', 'boolean'],
            'posting_schedule.*.times' => ['present', 'array', 'max:'.PostingSchedule::MAX_TIMES_PER_DAY],
            'posting_schedule.*.times.*' => ['required', 'date_format:H:i', $this->uniqueWithinDay(...)],
        ];
    }

    /**
     * Fails a time that appears more than once on its own day. Laravel's `distinct` rule
     * compares across every day, which would reject the same time on two days.
     */
    private function uniqueWithinDay(string $attribute, mixed $value, Closure $fail): void
    {
        $times = $this->input(Str::beforeLast($attribute, '.'));

        if (is_array($times) && count(array_keys($times, $value, true)) > 1) {
            $fail("The {$attribute} field has a duplicate value.");
        }
    }
}
