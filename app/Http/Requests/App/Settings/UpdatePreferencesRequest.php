<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Settings;

use App\Enums\User\DefaultPostAction;
use App\Enums\User\Theme;
use App\Enums\User\TimeFormat;
use App\Enums\User\WeekStart;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Each preference saves on its own as soon as it changes, so every field is
 * optional and only the ones sent are written.
 */
class UpdatePreferencesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'timezone' => ['sometimes', 'required', 'string', 'timezone:all'],
            'theme' => ['sometimes', 'required', Rule::enum(Theme::class)],
            'time_format' => ['sometimes', 'required', Rule::enum(TimeFormat::class)],
            'week_starts_on' => ['sometimes', 'required', Rule::enum(WeekStart::class)],
            'default_post_action' => ['sometimes', 'required', Rule::enum(DefaultPostAction::class)],
        ];
    }
}
