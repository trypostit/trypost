<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MoveChannelPostToQueueSlotRequest extends FormRequest
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
            'post_id' => ['required', 'uuid'],
            'slot_at' => ['required', 'date'],
        ];
    }
}
