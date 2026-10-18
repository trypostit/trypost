<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Inbox;

use Illuminate\Foundation\Http\FormRequest;

class ShowInboxConversationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
