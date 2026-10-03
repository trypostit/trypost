<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReorderChannelQueueRequest extends FormRequest
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
            'post_ids' => ['required', 'array', 'max:500'],
            'post_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
