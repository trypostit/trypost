<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use Illuminate\Foundation\Http\FormRequest;

class ReorderChannelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAccounts', $this->user()->currentWorkspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'social_account_ids' => ['required', 'array'],
            'social_account_ids.*' => ['required', 'uuid'],
        ];
    }
}
