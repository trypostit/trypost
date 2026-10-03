<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** Each switch saves on its own as soon as it changes, so every field is optional and only the ones sent are written. */
class UpdateNotificationPreferencesRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'post_published' => ['sometimes', 'required', 'boolean'],
            'post_failed' => ['sometimes', 'required', 'boolean'],
            'account_disconnected' => ['sometimes', 'required', 'boolean'],
            'post_note_added' => ['sometimes', 'required', 'boolean'],
            'collaboration' => ['sometimes', 'required', 'boolean'],
        ];
    }
}
